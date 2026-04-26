from flask import Flask, request, jsonify
from flask_cors import CORS
from deepface import DeepFace
import base64, numpy as np, cv2, os, logging

app = Flask(__name__)

# ── CORS: allow your InfinityFree domain + localhost for testing ──────────────
CORS(app, origins=[
    "http://localhost",
    "http://localhost:80",
    "http://127.0.0.1",
    "http://localhost:8000",
    "https://scholarshipmanagementsystem.infinityfreeapp.com/",   # ← replace with your InfinityFree URL
    "https://yourdomain.com",                   # ← replace with your custom domain (if any)
])

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

TEMP_FOLDER = "temp"
os.makedirs(TEMP_FOLDER, exist_ok=True)

MODEL_NAME      = "Facenet512"
DISTANCE_METRIC = "cosine"
MATCH_THRESHOLD = 0.25


def decode_base64_image(b64_string: str, save_path: str) -> bool:
    try:
        if "," in b64_string:
            b64_string = b64_string.split(",")[1]
        img_bytes = base64.b64decode(b64_string)
        np_arr    = np.frombuffer(img_bytes, np.uint8)
        img       = cv2.imdecode(np_arr, cv2.IMREAD_COLOR)
        if img is None:
            logger.error("cv2.imdecode returned None — invalid image bytes")
            return False
        cv2.imwrite(save_path, img)
        logger.info(f"Saved image to {save_path} ({os.path.getsize(save_path)} bytes)")
        return True
    except Exception as e:
        logger.error(f"Image decode error: {e}")
        return False


def has_face(img_path: str) -> bool:
    try:
        faces = DeepFace.extract_faces(
            img_path=img_path,
            enforce_detection=False,
            silent=True,
        )
        valid = [f for f in faces if f.get("confidence", 0) > 0.5]
        logger.info(f"Faces found: {len(faces)}, valid (conf>0.5): {len(valid)}")
        return len(valid) > 0
    except Exception as e:
        logger.warning(f"Face extraction error: {e}")
        return True


# ── Health check ──────────────────────────────────────────────────────────────
@app.route("/health", methods=["GET"])
def health():
    return jsonify({"status": "ok", "mode": "two-image-per-request"})


# ── Main scan endpoint ────────────────────────────────────────────────────────
@app.route("/scan", methods=["POST"])
def scan():
    """
    Expects JSON body:
    {
        "live_image":      "<base64 selfie>",
        "reference_image": "<base64 stored 2x2 photo>",
        "reference_name":  "Juan Dela Cruz"   // optional label
    }
    """
    data = request.get_json(silent=True)

    if not data:
        return jsonify({"error": "Invalid JSON body"}), 400
    if "live_image" not in data:
        return jsonify({"error": "Missing live_image field"}), 400
    if "reference_image" not in data:
        return jsonify({"error": "Missing reference_image field"}), 400

    # Use a unique temp path per request to avoid race conditions
    import uuid
    req_id       = uuid.uuid4().hex[:8]
    live_path    = os.path.join(TEMP_FOLDER, f"live_{req_id}.jpg")
    ref_path     = os.path.join(TEMP_FOLDER, f"ref_{req_id}.jpg")
    reference_name = str(data.get("reference_name", "unknown"))

    try:
        # Decode both images
        if not decode_base64_image(data["live_image"], live_path):
            return jsonify({"error": "Invalid live_image — could not decode."}), 400

        if not decode_base64_image(data["reference_image"], ref_path):
            return jsonify({"error": "Invalid reference_image — could not decode."}), 400

        # Check for a face in the live photo
        if not has_face(live_path):
            return jsonify({
                "status":  "no_face",
                "message": "No face detected in the selfie. Please take a clear, well-lit photo facing the camera."
            })

        # Verify
        result   = DeepFace.verify(
            img1_path=live_path,
            img2_path=ref_path,
            model_name=MODEL_NAME,
            distance_metric=DISTANCE_METRIC,
            enforce_detection=False,
            silent=True,
        )
        distance   = round(result["distance"], 4)
        MAX_DIST   = 0.6
        confidence = round(max(0.0, (1 - distance / MAX_DIST) * 100), 1)
        matched    = distance <= MATCH_THRESHOLD

        logger.info(f"Scan result: distance={distance}, matched={matched}, name={reference_name}")

        if matched:
            return jsonify({
                "status":       "matched",
                "matched_with": reference_name,
                "distance":     distance,
                "confidence":   min(confidence, 100.0),
            })
        else:
            return jsonify({
                "status":             "no_match",
                "closest":            reference_name,
                "closest_confidence": min(confidence, 100.0),
                "distance":           distance,
            })

    except Exception as e:
        logger.error(f"Scan error: {e}")
        return jsonify({"status": "error", "message": str(e)}), 500

    finally:
        # Always clean up temp files
        for path in [live_path, ref_path]:
            if os.path.exists(path):
                os.unlink(path)


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port, debug=False)