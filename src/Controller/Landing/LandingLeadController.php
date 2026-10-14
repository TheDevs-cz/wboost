<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Landing;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use WBoost\Web\Message\Landing\CaptureLandingLead;
use WBoost\Web\Value\LandingLeadSource;

/**
 * The one write endpoint behind the static landing site (landing/README.md):
 * the contact forms on `/` and `/klienti` and the chat bubble on `/klienti`
 * POST here. The path is NOT in the landing's Traefik rule, so it reaches the
 * app on the same origin — no CORS.
 *
 * No CSRF token: the pages are static files with no session to bind one to,
 * and a forged submission can do nothing a visitor cannot do by hand. Abuse is
 * bounded by the per-address rate limit and a honeypot field.
 *
 * Invalid input is answered with a plain 422 JSON body rather than an
 * HttpException — every thrown 4xx is booked as a Sentry issue, and a typo in
 * an e-mail address is not one.
 *
 * Content negotiation: the pages submit with fetch() and `Accept:
 * application/json`; without JavaScript the browser posts the form natively
 * and is redirected back to the page with `?odeslano` / `?chyba`.
 */
final class LandingLeadController extends AbstractController
{
    public const string PATH = '/poptavka';

    /** Hidden field real visitors never see; bots fill it. */
    private const string HONEYPOT_FIELD = 'fax';

    private const int MAX_LINE = 255;
    private const int MAX_MESSAGE = 5000;
    private const int MAX_INTERESTS = 20;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route(path: self::PATH, name: 'landing_lead', methods: ['POST'])]
    #[RateLimit('landing_lead')]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getPayload();
        $source = LandingLeadSource::tryFrom($this->line($payload, 'source') ?? '');

        if ($source === null) {
            return $this->respond($request, null, ['source' => 'Neznámý formulář.']);
        }

        // Pretend success so a bot learns nothing; store and send nothing.
        if ($this->line($payload, self::HONEYPOT_FIELD) !== null) {
            return $this->respond($request, $source, []);
        }

        $email = $this->line($payload, 'email');
        $name = $this->line($payload, 'name');
        $message = $this->text($payload, 'message');
        $hasDesigner = match ($this->line($payload, 'hasDesigner')) {
            'Ano' => true,
            'Ne' => false,
            default => null,
        };
        $designerEmail = $hasDesigner === true ? $this->line($payload, 'designerEmail') : null;

        $errors = [];

        if ($email === null) {
            $errors['email'] = 'Vyplňte prosím e-mail.';
        } elseif ($this->isEmail($email) === false) {
            $errors['email'] = 'Tohle nevypadá jako e-mailová adresa.';
        }

        if ($source === LandingLeadSource::Chat) {
            if ($message === null) {
                $errors['message'] = 'Napište nám prosím zprávu.';
            }
        } elseif ($name === null) {
            $errors['name'] = 'Vyplňte prosím jméno.';
        }

        if ($designerEmail !== null && $this->isEmail($designerEmail) === false) {
            $errors['designerEmail'] = 'Tohle nevypadá jako e-mailová adresa.';
        }

        if ($errors !== [] || $email === null) {
            return $this->respond($request, $source, $errors);
        }

        $this->bus->dispatch(new CaptureLandingLead(
            source: $source,
            email: $email,
            name: $name,
            phone: $this->line($payload, 'phone'),
            company: $this->line($payload, 'company'),
            website: $this->line($payload, 'website'),
            interests: $this->interests($payload),
            hasDesigner: $hasDesigner,
            designerName: $hasDesigner === true ? $this->line($payload, 'designerName') : null,
            designerEmail: $designerEmail,
            message: $message,
        ));

        return $this->respond($request, $source, []);
    }

    /**
     * @param InputBag<bool|float|int|string> $payload
     */
    private function line(InputBag $payload, string $key): null|string
    {
        $value = $payload->get($key);

        if (is_string($value) === false) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, self::MAX_LINE);
    }

    /**
     * @param InputBag<bool|float|int|string> $payload
     */
    private function text(InputBag $payload, string $key): null|string
    {
        $value = $payload->get($key);

        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::MAX_MESSAGE);
    }

    /**
     * The tag input on /klienti submits one comma-joined hidden field.
     *
     * @param InputBag<bool|float|int|string> $payload
     *
     * @return list<string>
     */
    private function interests(InputBag $payload): array
    {
        $joined = $this->line($payload, 'interests');

        if ($joined === null) {
            return [];
        }

        $interests = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $joined)),
            static fn (string $interest): bool => $interest !== '',
        )));

        return array_slice($interests, 0, self::MAX_INTERESTS);
    }

    private function isEmail(string $value): bool
    {
        return count($this->validator->validate($value, new Email(mode: Email::VALIDATION_MODE_HTML5))) === 0;
    }

    /**
     * @param array<string, string> $errors
     */
    private function respond(Request $request, null|LandingLeadSource $source, array $errors): Response
    {
        if (in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            $response = $errors === []
                ? new JsonResponse(['ok' => true], Response::HTTP_CREATED)
                : new JsonResponse(['ok' => false, 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $page = $source === LandingLeadSource::Designers ? '/' : '/klienti';
        $anchor = $source === LandingLeadSource::Designers ? '#kontakt' : '#formular';

        return new RedirectResponse($page . ($errors === [] ? '?odeslano' : '?chyba') . $anchor, Response::HTTP_SEE_OTHER);
    }
}
