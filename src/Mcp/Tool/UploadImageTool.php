<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Mcp\Response\UploadImageResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Image\NormalizeImageFormat;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

/**
 * `upload_image` — put a NEW picture into a project's gallery, and get back the
 * asset id every other tool already speaks.
 *
 * The write counterpart of `list_gallery`, and the only writing this release
 * does to a user's assets: there is no delete, move or rename tool, and this
 * one never replaces an existing row. Every call creates a new picture, so the
 * worst an agent can do is add clutter a human can throw away — not destroy
 * something.
 *
 * ## base64 ONLY — this tool does not fetch URLs, deliberately
 *
 * Accepting a URL would mean this server issuing an outbound HTTP request to an
 * address a caller chose, from inside the network that also hosts Gotenberg,
 * Minio, Redis and Postgres — a textbook SSRF, and one an agent is unusually
 * easy to talk into (the "caller" here is a model that may be reading an
 * attacker's web page). {@see \WBoost\Web\Services\OAuth2\DynamicClientRegistrar}
 * refused the identical trade for RFC 7591's URL-valued metadata fields and is
 * the precedent followed here.
 *
 * A safe fetcher is not one check but a standing commitment: https only, DNS
 * resolution pinned and re-checked after redirects (rebinding), every private /
 * loopback / link-local / ULA range blocked on the resolved ADDRESS, redirects
 * bounded, content type verified, body capped mid-stream, a tight timeout — and
 * it must keep holding as the network around it changes. The gain is nil: the
 * host model already has the picture the user dropped into the chat, so base64
 * is the shorter path anyway. A partial fetcher would be strictly worse than
 * none, so there is none. The parameter is NAMED `imageBase64` so this is
 * obvious before the call, and a URL sent in that slot is refused with the
 * reason rather than a base64 decode failure.
 *
 * ## Why the bytes go through the message bus and nowhere else
 *
 * The payload is dispatched as {@see UploadFile} — the single chokepoint the
 * project gallery form and both placeholder upload endpoints also pass through
 * — so a picture uploaded by an agent is byte-for-byte as normalised as one
 * uploaded by a human: {@see NormalizeImageFormat} transcodes anything the rest
 * of the stack cannot read (HEIC/HEIF above all, the default iPhone format,
 * which fails `getimagesizefromstring()`, a browser `<img>` AND Gotenberg's
 * Chromium alike), bakes in EXIF orientation, preserves the colour profile, and
 * names the stored object with the extension describing the BYTES rather than
 * whatever the caller called the file. SVG is passed through untouched: it is a
 * first-class vector asset here and must stay one.
 *
 * The normaliser is nevertheless run ONCE HERE first, before anything is
 * written, for two reasons that outweigh the duplicated work (which is a bare
 * `getimagesizefromstring()` for the four web-safe formats — only an exotic
 * upload pays for a second decode):
 *
 * 1. **Nothing unusable reaches storage.** A PDF, a ZIP or a truncated payload
 *    would otherwise be stored under the caller's extension and become a
 *    gallery entry that renders nowhere. Refusing it costs the caller one
 *    error; storing it costs a human a cleanup.
 * 2. **The reply can state the STORED size** without reading the object back
 *    out of Minio — and the stored size is the one that matters, since it is
 *    the transcoded, orientation-corrected picture every later consumer reads.
 *
 * ## Two size limits, and only one of them is wboost's
 *
 * {@see PlaceholderImageUploader::MAX_FILE_SIZE_BYTES} — 10 MB **decimal**, the
 * number every other upload path in the app mirrors — is the limit enforced
 * here, by reusing that constant rather than restating it (a file in the gap
 * between 10 MB and 10 MiB accepted by one layer and refused by the next is
 * exactly the bug that convention exists to prevent).
 *
 * It is, today, unreachable through this transport. `StreamableHttpTransport`
 * caps a POST body at {@see \Mcp\Server\Transport\StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES}
 * (4 MiB) and the bundle exposes no way to raise it; base64 inflates bytes by
 * 4/3, so the whole JSON-RPC envelope leaves room for roughly
 * {@see self::TRANSPORT_LIMIT_BYTES} of picture. Anything larger is rejected by
 * the transport with a `413` before this class runs. Both numbers are therefore
 * named in the refusal and in the tool description: an agent that is told only
 * "10 MB" will keep resending a 6 MB photo and keep getting a bare HTTP error
 * it cannot interpret.
 *
 * ## Authorisation
 *
 * {@see ProjectVoter::VIEW} — the gate the plan assigns this tool, and the same
 * one `list_gallery` applies. The gallery is a project-wide library shared by
 * every surface, so anyone who may SEE a project may add to its library; what
 * narrows an agent is the `gallery:write` scope, which no other scope implies.
 * A project the caller cannot reach reports the SAME failure as an id that
 * matches no row ({@see notFound()}), and a folder belonging to another project
 * is likewise indistinguishable from one that never existed — the
 * {@see \WBoost\Web\Twig\Components\Project\ImageGallery} `ownedDirectory()`
 * guard, applied here.
 */
#[McpToolScope(McpScope::GalleryWrite)]
readonly final class UploadImageTool
{
    /**
     * The app-wide upload cap, taken from the constant every other path uses.
     * 10 MB DECIMAL (10 000 000 bytes) — see that constant for why the decimal
     * reading is not a rounding choice but the one Symfony's `maxSize: '10m'`
     * makes.
     */
    private const int MAX_FILE_SIZE_BYTES = PlaceholderImageUploader::MAX_FILE_SIZE_BYTES;

    /**
     * Roughly what a base64 payload can carry through a 4 MiB request body:
     * `(4 * 1024 * 1024) * 3 / 4` is 3 145 728 bytes before the JSON-RPC
     * envelope, so 3 000 000 is the honest round number to quote a caller.
     *
     * Public because it is a documented ceiling rather than an implementation
     * detail — a test locks it against
     * {@see \Mcp\Server\Transport\StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES},
     * so an SDK bump that lowers the body cap fails the build here instead of
     * silently turning the advice in the tool description into a lie.
     */
    public const int TRANSPORT_LIMIT_BYTES = 3 * 1000 * 1000;

    public function __construct(
        private Security $security,
        private ProjectRepository $projectRepository,
        private FileDirectoryRepository $fileDirectoryRepository,
        private FileUploadRepository $fileUploadRepository,
        private NormalizeImageFormat $normalizeImageFormat,
        private ProvideIdentity $provideIdentity,
        private MessageBusInterface $bus,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * Adds a picture to a project's image gallery and returns the id every
     * other tool accepts for it: as an image-placeholder fill in render_variant
     * and export_variant, and as an `asset` (a background, a logo, a photo) in
     * a design document. Call get_context first — its projects[].id is the
     * projectId this tool takes.
     *
     * The picture is sent INLINE, base64-encoded, in imageBase64. URLs are not
     * accepted and are never fetched: dereferencing a caller-supplied address
     * from inside wboost's own network would be a server-side request forgery,
     * so if you have a link, download it yourself and send the bytes. A
     * data: URI is tolerated — its header is stripped for you.
     *
     * Keep the picture under about 3 MB. base64 makes bytes a third longer and
     * one MCP request body is capped at 4 MiB, so a larger photo fails at the
     * transport with a bare HTTP 413 that says nothing useful; resize or
     * re-encode it first. The hard limit wboost itself applies to any upload is
     * 10 MB.
     *
     * PNG, JPEG, GIF, WebP and SVG are stored as they are. A phone photo
     * (HEIC/HEIF) is converted to JPEG on the way in, with its rotation baked
     * into the pixels — every wboost surface can then read it, which is not
     * true of the original. Anything that is not a picture at all is refused
     * and nothing is stored. The reply reports the size of what was actually
     * stored, which is what matters when you place it; width and height are
     * null for an SVG, because a vector scales to whatever box it is put in.
     *
     * Omit directoryId to file the picture at the gallery ROOT, which is a real
     * location, not a fallback. To put it in a folder, pass an id from
     * list_gallery's directories[]. filename is a label for you and the
     * user — wboost names the stored object after its own id and gives it the
     * extension its bytes require, so a name cannot make a file something it is
     * not.
     *
     * Nothing is ever overwritten: each call adds a new picture. There is no
     * way to delete, move or rename a gallery image from an AI client, so an
     * upload made by mistake has to be cleaned up by a human in wboost. A
     * project id this account cannot see reports exactly the same failure as an
     * id that does not exist.
     *
     * @param string $projectId UUID of the project whose gallery to add to, as returned by get_context in projects[].id.
     * @param string $imageBase64 The picture's bytes, base64-encoded (a data: URI is accepted too). Not a URL — nothing is fetched.
     * @param string $filename A name for the picture, e.g. "logo.svg". Used as a label and as the format hint for vector files; the stored object is named after its own id.
     * @param null|string $directoryId UUID of the gallery folder to file it in, from list_gallery's directories[]. Omit it to file the picture at the gallery root.
     */
    #[McpTool(name: 'upload_image')]
    public function __invoke(
        string $projectId,
        string $imageBase64,
        string $filename,
        null|string $directoryId = null,
    ): UploadImageResponse {
        $project = $this->project($projectId);
        $directory = $directoryId !== null ? $this->directory($project, $directoryId) : null;

        $bytes = self::decode($imageBase64);

        if (strlen($bytes) > self::MAX_FILE_SIZE_BYTES) {
            throw new ToolCallException(sprintf(
                'That picture is %d bytes, over the %d-byte limit (10 MB) wboost applies to every upload. In practice an MCP upload is capped lower still — around %d bytes — because base64 makes the payload a third longer and one request body may not exceed 4 MiB. Resize or re-encode the picture and send it again.',
                strlen($bytes),
                self::MAX_FILE_SIZE_BYTES,
                self::TRANSPORT_LIMIT_BYTES,
            ));
        }

        // Run BEFORE anything is written, so an unusable payload costs the
        // caller an error rather than costing a human a cleanup — and so the
        // reply can state the size of the bytes that end up stored. `null` here
        // means "not a raster this app handles", which is an SVG (kept vector
        // on purpose) or something that is not a picture at all.
        $normalized = $this->normalizeImageFormat->normalize($bytes);

        if ($normalized === null && !self::looksLikeSvg($bytes)) {
            throw new ToolCallException(
                'Those bytes are not a picture wboost can use. Send a PNG, JPEG, GIF, WebP, SVG or a phone photo (HEIC/HEIF), base64-encoded on its own with no surrounding text — an archive, a text file or a truncated payload cannot become a gallery image. Nothing was uploaded.',
            );
        }

        $fileId = $this->provideIdentity->next();

        $this->store(
            $project,
            $directory,
            $fileId,
            $bytes,
            self::clientName($filename, $normalized['extension'] ?? 'svg'),
            $normalized['mimeType'] ?? 'image/svg+xml',
        );

        $upload = $this->fileUploadRepository->get($fileId);

        return new UploadImageResponse(
            imageId: $upload->id->toString(),
            url: $this->uploaderHelper->getPublicPath($upload->path),
            // An SVG reports nulls, exactly as `list_gallery` and the design
            // compiler do for one: a vector has no pixel size, and inventing
            // an aspect ratio silently mis-crops every placement made from it.
            width: $normalized['width'] ?? null,
            height: $normalized['height'] ?? null,
        );
    }

    /**
     * Hands the bytes to {@see UploadFile} — the shared upload chokepoint —
     * through the command bus, which is where normalisation, storage and the
     * `file_upload` row all happen (and where the transaction middleware
     * flushes; nothing here calls `flush()`).
     *
     * The message carries an {@see UploadedFile}, so the payload has to exist
     * as a real file for the length of the dispatch. It is written to the
     * system temp directory and removed in `finally` — including on the failure
     * path, where an abandoned copy of a user's picture is exactly what must
     * not be left behind. `test: true` is not a testing shortcut: it is the
     * flag that says "this is a local file, not a PHP multipart upload", which
     * is the truth here and keeps `isValid()` from asserting otherwise.
     */
    private function store(
        Project $project,
        null|FileDirectory $directory,
        UuidInterface $fileId,
        string $bytes,
        string $clientName,
        string $mimeType,
    ): void {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'wboost-mcp-upload-');

        if ($temporaryPath === false) {
            throw new ToolCallException('The picture could not be staged for upload on the server. Nothing was uploaded; try again.');
        }

        if (file_put_contents($temporaryPath, $bytes) === false) {
            // tempnam() already created the (empty) file, so it has to go even
            // though nothing was written into it.
            @unlink($temporaryPath);

            throw new ToolCallException('The picture could not be staged for upload on the server. Nothing was uploaded; try again.');
        }

        try {
            $this->bus->dispatch(new UploadFile(
                $fileId,
                $project->id,
                FileSource::ProjectImage,
                new UploadedFile($temporaryPath, $clientName, $mimeType, null, true),
                $directory?->id,
            ));
        } catch (\Throwable $failure) {
            // The project and the folder were both resolved above, so a failure
            // here is the storage backend or the database, never the caller's
            // arguments — say so rather than letting it degrade into the SDK's
            // generic "Error while executing tool".
            throw new ToolCallException(sprintf(
                'Storing the picture failed: %s. Nothing was uploaded — this is a problem on the wboost side, not with the picture.',
                $failure->getMessage(),
            ));
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * The project, or the one refusal this tool ever gives for a project id.
     */
    private function project(string $projectId): Project
    {
        if (!Uuid::isValid($projectId)) {
            // NOT folded into notFound(): a string that cannot be a project id
            // reveals nothing about which projects exist, and telling the agent
            // it sent a name where a UUID belongs is the difference between a
            // fixable mistake and a silent dead end.
            throw new ToolCallException(sprintf(
                '"%s" is not a valid project id. Project ids are UUIDs; call get_context to list the ones this account can reach.',
                $projectId,
            ));
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw self::notFound($projectId);
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw self::notFound($projectId);
        }

        return $project;
    }

    /**
     * A client-supplied folder id resolved ONLY if it belongs to this project's
     * image gallery — the guard that keeps a VIEW grant on one project from
     * writing into another's folders. Same indistinguishability rule as
     * {@see notFound()}.
     */
    private function directory(Project $project, string $directoryId): FileDirectory
    {
        if (!Uuid::isValid($directoryId)) {
            throw new ToolCallException(sprintf(
                '"%s" is not a valid folder id. Folder ids are UUIDs; omit directoryId to upload into the gallery root.',
                $directoryId,
            ));
        }

        try {
            $directory = $this->fileDirectoryRepository->get(Uuid::fromString($directoryId));
        } catch (FileDirectoryNotFound) {
            throw self::directoryNotFound($directoryId);
        }

        // Source isolation is enforced by the single FileSource case today (the
        // gallery IS the project image library); when FileSource gains a case,
        // also guard `$directory->source === FileSource::ProjectImage` here —
        // the same note `ListGalleryTool` and the web gallery both carry.
        if (!$directory->project->id->equals($project->id)) {
            throw self::directoryNotFound($directoryId);
        }

        return $directory;
    }

    /**
     * The refusal, worded once. Both callers — "no such row" and "not yours" —
     * must produce a byte-identical message; see the class docblock. It is the
     * same sentence `list_gallery` gives, so an agent that just browsed a
     * gallery and then tried to add to it reads one wording, not two.
     */
    private static function notFound(string $projectId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Project %s was not found, or this account cannot access it. Call get_context for the projects it can reach.',
            $projectId,
        ));
    }

    /**
     * The folder counterpart of {@see notFound()} — one wording for "no such
     * folder" and "belongs to another project" alike.
     */
    private static function directoryNotFound(string $directoryId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Folder %s was not found in this project. Call list_gallery to see its folders, or omit directoryId to upload into the gallery root.',
            $directoryId,
        ));
    }

    /**
     * The caller's base64 turned into bytes, or a refusal that says which of the
     * several ways this goes wrong actually happened.
     *
     * Two leniencies, both because a model composing a tool call is the caller:
     * a `data:` URI header is stripped (agents copy pictures around in that
     * form), and whitespace — including the line breaks of a 76-column MIME
     * encoding — is removed before decoding. Padding is completed rather than
     * demanded, since strict decoding is what protects against garbage, and a
     * dropped `=` is not garbage.
     *
     * A URL is caught explicitly. It is a plausible thing for an agent to try,
     * and "invalid base64" would send it hunting for an encoding bug instead of
     * telling it the truth: this server does not fetch (see the class docblock).
     */
    private static function decode(string $value): string
    {
        $payload = trim($value);

        if (stripos($payload, 'data:') === 0) {
            $comma = strpos($payload, ',');

            if ($comma === false) {
                throw new ToolCallException('That data: URI has no comma separating its header from the payload, so there are no bytes to store. Send the base64 payload on its own.');
            }

            $payload = substr($payload, $comma + 1);
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $payload) === 1) {
            throw new ToolCallException('imageBase64 takes the picture\'s bytes, base64-encoded — not a URL. wboost never fetches a link a caller supplies (doing so from inside its own network would be a server-side request forgery), so download the picture yourself and send the bytes.');
        }

        $payload = (string) preg_replace('~\s+~', '', $payload);

        if ($payload === '') {
            throw new ToolCallException('imageBase64 was empty. Send the picture\'s bytes, base64-encoded.');
        }

        $remainder = strlen($payload) % 4;

        if ($remainder !== 0) {
            $payload .= str_repeat('=', 4 - $remainder);
        }

        $bytes = base64_decode($payload, true);

        if ($bytes === false || $bytes === '') {
            throw new ToolCallException('imageBase64 is not valid base64. Send the picture\'s raw bytes encoded as base64 — not a URL, not a file path, and not JSON wrapping the data.');
        }

        return $bytes;
    }

    /**
     * The name handed to the upload chokepoint: the caller's, with the
     * extension replaced by the one the CONTENT requires.
     *
     * For a raster this changes nothing observable — the handler overrides the
     * extension with the normaliser's anyway — but it is what makes the SVG
     * case fall out of the same rule instead of needing its own. A vector is
     * the one upload whose stored extension comes from the client name, so
     * `photo.png` carrying SVG bytes would otherwise be stored as a PNG that
     * paints nowhere.
     */
    private static function clientName(string $filename, string $extension): string
    {
        // Backslashes first: a Windows-style path is not split by basename() on
        // Linux, so "C:\pics\logo.svg" would arrive whole.
        $name = basename(str_replace('\\', '/', trim($filename)));
        $stem = pathinfo($name, PATHINFO_FILENAME);

        if ($stem === '') {
            $stem = 'image';
        }

        return $stem . '.' . $extension;
    }

    /**
     * Mirrors {@see NormalizeImageFormat}'s own SVG sniff — content, not file
     * name, so an SVG sent as "logo.txt" is still an SVG. Duplicated rather
     * than shared because the normaliser's copy is private and reshaping a
     * service half the app uploads through is not this tool's business; it is
     * two lines, and the rule it encodes ("an `<svg` root near the start")
     * cannot drift far.
     */
    private static function looksLikeSvg(string $contents): bool
    {
        return stripos(substr($contents, 0, 1024), '<svg') !== false;
    }
}
