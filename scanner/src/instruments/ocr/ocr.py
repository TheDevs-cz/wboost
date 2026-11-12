"""OCR sidecar of the scanner: RapidOCR (PP-OCRv5 mobile detector + latin recogniser, ONNX Runtime, CPU).

usage: ocr.py <image> [--threads N] [--scale F]

Prints ONE JSON object on stdout:
  {engine, threads, scale, lines: [{text, conf, box: [x, y, w, h]}], seconds, loadSeconds, peakRssMb, cpuSeconds}
Coordinates are pixels of the image as given (a --scale resample is divided back out).
The thread count is pinned (intra-op = N, inter-op = 1, OMP capped) so that N concurrent scans
never ask for more cores than the container was given.
"""
import json, os, resource, sys, time


def arg(name, default):
    return type(default)(sys.argv[sys.argv.index(name) + 1]) if name in sys.argv else default


path = sys.argv[1]
threads = max(1, arg("--threads", 3))
scale = arg("--scale", 1.0)
for key in ("OMP_NUM_THREADS", "OMP_THREAD_LIMIT", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    os.environ[key] = str(threads)

t0 = time.time()
from rapidocr import RapidOCR  # noqa: E402
from rapidocr.utils.typings import LangDet, LangRec, ModelType, OCRVersion  # noqa: E402

src = path
if scale != 1.0:
    from PIL import Image

    im = Image.open(path).convert("RGB")
    im = im.resize((round(im.width * scale), round(im.height * scale)), Image.LANCZOS)
    src = path + ".scaled.png"
    im.save(src)

engine = RapidOCR(params={
    "Global.log_level": "critical",
    "Det.ocr_version": OCRVersion.PPOCRV5, "Det.model_type": ModelType.MOBILE, "Det.lang_type": LangDet.CH,
    "Rec.ocr_version": OCRVersion.PPOCRV5, "Rec.model_type": ModelType.MOBILE, "Rec.lang_type": LangRec.LATIN,
    "EngineConfig.onnxruntime.intra_op_num_threads": threads,
    "EngineConfig.onnxruntime.inter_op_num_threads": 1,
})
load = time.time() - t0
t0 = time.time()
r = engine(src)
seconds = time.time() - t0
lines = []
if r.boxes is not None:
    for box, txt, score in zip(r.boxes, r.txts, r.scores):
        xs = [float(x) / scale for x, _ in box]
        ys = [float(y) / scale for _, y in box]
        lines.append({"text": txt, "conf": round(float(score), 4),
                      "box": [round(min(xs), 1), round(min(ys), 1), round(max(xs) - min(xs), 1), round(max(ys) - min(ys), 1)]})
if src != path:
    os.remove(src)
ru = resource.getrusage(resource.RUSAGE_SELF)
# ru_maxrss: kilobytes on Linux, bytes on macOS
rss_mb = ru.ru_maxrss / (1024 * 1024 if sys.platform == "darwin" else 1024)
print(json.dumps({"engine": "rapidocr", "threads": threads, "scale": scale, "lines": lines, "seconds": round(seconds, 3),
                  "loadSeconds": round(load, 3), "peakRssMb": round(rss_mb, 1), "cpuSeconds": round(ru.ru_utime + ru.ru_stime, 3)},
                 ensure_ascii=False))
