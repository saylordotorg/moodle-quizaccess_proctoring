import base64
import hmac
import json
import math
import os
from typing import Any

import boto3
from botocore.exceptions import BotoCoreError, ClientError

from name_matching import profile_names, score_name_match


API_KEY = os.getenv("FACE_API_KEY", "")
AWS_REGION = os.getenv("AWS_REGION", "us-east-1")
SIMILARITY_THRESHOLD = float(os.getenv("FACE_SIMILARITY_THRESHOLD", "80"))
ID_FACE_THRESHOLD = float(os.getenv("ID_FACE_THRESHOLD", "80"))
ID_NAME_THRESHOLD = float(os.getenv("ID_NAME_THRESHOLD", "80"))
MAX_REQUEST_BYTES = 6 * 1024 * 1024
MAX_IMAGE_BYTES = 5 * 1024 * 1024
MAX_IMAGE_TEXT = ((MAX_IMAGE_BYTES + 2) // 3) * 4 + 64

rekognition = boto3.client("rekognition", region_name=AWS_REGION)


def response(status_code: int, body: dict[str, Any]) -> dict[str, Any]:
    return {
        "statusCode": status_code,
        "headers": {"Content-Type": "application/json"},
        "body": json.dumps(body),
    }


def get_header(event: dict[str, Any], name: str) -> str:
    headers = event.get("headers") or {}
    for key, value in headers.items():
        if key.lower() == name.lower():
            return value or ""
    return ""


def decode_image(image_data: str) -> bytes:
    if not isinstance(image_data, str) or not image_data or len(image_data) > MAX_IMAGE_TEXT:
        raise ValueError("Invalid image data.")
    if "," in image_data:
        image_data = image_data.split(",", 1)[1]
    decoded = base64.b64decode(image_data, validate=True)
    if not decoded or len(decoded) > MAX_IMAGE_BYTES:
        raise ValueError("Invalid image size.")
    return decoded


def valid_threshold(value: Any, default: float) -> float:
    threshold = float(default if value is None else value)
    if isinstance(value, bool) or not math.isfinite(threshold) or not 0 < threshold <= 100:
        raise ValueError("Threshold must be greater than zero and at most 100.")
    return threshold


def payload_bool(payload: dict[str, Any], key: str, default: bool = True) -> bool:
    value = payload.get(key)
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return value != 0

    normalized = str(value).strip().lower()
    if normalized in {"0", "false", "no", "off"}:
        return False
    if normalized in {"1", "true", "yes", "on"}:
        return True
    return default


def best_similarity(compare_response: dict[str, Any]) -> float:
    matches = compare_response.get("FaceMatches", [])
    if not matches:
        return 0.0
    return max(float(match.get("Similarity", 0)) for match in matches)


def detect_id_text(id_image: bytes) -> list[str]:
    detect_response = rekognition.detect_text(Image={"Bytes": id_image})
    return [
        str(item["DetectedText"])
        for item in detect_response.get("TextDetections", [])
        if item.get("Type") == "LINE" and item.get("DetectedText")
    ]


def reference_face_state(reference: bytes) -> str:
    """Whether Rekognition can find a face in the reference image: "yes", "no" or "unknown".

    compare_faces raises InvalidParameterException when either image has no face, so a
    reference saved with the face out of frame looks exactly like a mismatch. Comparing the
    reference with itself tells the two apart using the same permission. Any other failure is
    "unknown", so an outage never retires a usable reference or blames the webcam image.
    """
    try:
        rekognition.compare_faces(
            SourceImage={"Bytes": reference},
            TargetImage={"Bytes": reference},
            SimilarityThreshold=0,
        )
    except ClientError as exc:
        if exc.response.get("Error", {}).get("Code") == "InvalidParameterException":
            return "no"
        return "unknown"
    except BotoCoreError:
        return "unknown"
    return "yes"



def verify_face_payload(payload: dict[str, Any]) -> dict[str, Any]:
    reference_image = payload.get("reference_image") or payload.get("image_reference")
    current_image = payload.get("current_snap") or payload.get("image_current")
    if not reference_image or not current_image:
        return response(
            422,
            {"detail": "Provide reference_image/current_snap or image_reference/image_current."},
        )

    try:
        reference = decode_image(reference_image)
        current = decode_image(current_image)
    except ValueError:
        return response(400, {"detail": "Invalid base64 image data."})

    try:
        compare_response = rekognition.compare_faces(
            SourceImage={"Bytes": reference},
            TargetImage={"Bytes": current},
            SimilarityThreshold=0,
        )
    except ClientError as exc:
        error_code = exc.response.get("Error", {}).get("Code")
        if error_code == "InvalidParameterException":
            state = reference_face_state(reference)
            if state == "no":
                return response(200, {
                    "match": False,
                    "reason": "reference_no_face",
                    "message": "No face found in the reference image.",
                })
            if state == "yes":
                # The reference has a face, so the webcam image is the one without: the student
                # was not in view, which is not the same as being someone else.
                return response(200, {
                    "match": False,
                    "reason": "no_face_in_capture",
                    "message": "No face found in the webcam image.",
                })
            return response(200, {"match": False, "message": "Face does not match."})
        return response(502, {"detail": "AWS Rekognition compare_faces failed."})
    except BotoCoreError:
        return response(502, {"detail": "AWS Rekognition compare_faces failed."})

    score = best_similarity(compare_response)
    if score >= SIMILARITY_THRESHOLD:
        rounded = round(score, 2)
        return response(200, {
            "match": True,
            "score": rounded,
            "similarity": rounded,
            "message": "Face verified successfully.",
        })

    # The similarity is returned for a non-match too, so the caller can apply its own threshold.
    rounded = round(score, 2)
    return response(200, {
        "match": False,
        "score": rounded,
        "similarity": rounded,
        "message": "Face does not match.",
    })


def verify_id_payload(payload: dict[str, Any]) -> dict[str, Any]:
    try:
        face_threshold = valid_threshold(payload.get("face_threshold"), ID_FACE_THRESHOLD)
        name_threshold = valid_threshold(payload.get("name_threshold"), ID_NAME_THRESHOLD)
    except (ValueError, TypeError):
        return response(422, {"detail": "Invalid verification threshold."})
    for field in ("profile_firstname", "profile_lastname", "profile_fullname"):
        value = payload.get(field)
        if value is not None and (not isinstance(value, str) or len(value) > 600):
            return response(422, {"detail": "Invalid profile name."})
    variants = payload.get("profile_name_variants")
    if variants is not None and (
        not isinstance(variants, list)
        or len(variants) > 100
        or any(not isinstance(item, str) or len(item) > 600 for item in variants)
    ):
        return response(422, {"detail": "Invalid profile name variants."})
    check_face = payload_bool(payload, "check_face", True)
    check_name = payload_bool(payload, "check_name", True)
    if not check_face and not check_name:
        check_face = True
        check_name = True

    if not payload.get("id_image") or (check_face and not payload.get("live_image")):
        return response(422, {"detail": "Provide id_image and live_image."})

    try:
        id_image = decode_image(payload["id_image"])
        live_image = decode_image(payload["live_image"]) if payload.get("live_image") else b""
    except ValueError:
        return response(400, {"detail": "Invalid base64 image data."})

    compare_response = {}
    text_lines = []
    try:
        if check_face:
            compare_response = rekognition.compare_faces(
                SourceImage={"Bytes": id_image},
                TargetImage={"Bytes": live_image},
                SimilarityThreshold=0,
            )
        if check_name:
            text_lines = detect_id_text(id_image)
    except ClientError as exc:
        error_code = exc.response.get("Error", {}).get("Code")
        if error_code == "InvalidParameterException":
            return response(200, {
                "verified": False,
                "status": "retry",
                "face_score": 0,
                "name_score": 0,
                "message": "Could not find a usable face or text in the ID image.",
            })
        return response(502, {"detail": "AWS Rekognition ID verification failed."})
    except BotoCoreError:
        return response(502, {"detail": "AWS Rekognition ID verification failed."})

    face_score = best_similarity(compare_response) if check_face else 100.0
    if check_name:
        names = profile_names(
            payload.get("profile_fullname"),
            payload.get("profile_firstname"),
            payload.get("profile_lastname"),
            variants,
        )
        name = score_name_match(text_lines, names)
    else:
        name = {"name_score": 100.0, "extracted_name": "", "matched_profile_name": "",
                "name_match_reason": "", "name_readable": True}
    name_score = name["name_score"]
    verified = (not check_face or face_score >= face_threshold) and (not check_name or name_score >= name_threshold)

    return response(200, {
        "verified": verified,
        "status": "pass" if verified else "failed",
        "face_score": round(face_score, 2),
        "name_score": round(name_score, 2),
        "extracted_name": name["extracted_name"],
        "matched_profile_name": name["matched_profile_name"],
        "name_match_reason": name["name_match_reason"],
        "name_readable": name["name_readable"],
        "message": "ID verified." if verified else "ID verification did not pass.",
    })


def lambda_handler(event: dict[str, Any], context: Any) -> dict[str, Any]:
    if not API_KEY:
        return response(500, {"detail": "FACE_API_KEY is not configured."})

    supplied_key = get_header(event, "X-API-Key")
    if not isinstance(supplied_key, str) or not hmac.compare_digest(supplied_key.encode(), API_KEY.encode()):
        return response(401, {"detail": "Invalid API key."})

    # Function URLs/HTTP API v2 and API Gateway REST API use different method fields.
    request_context = event.get("requestContext")
    http = request_context.get("http") if isinstance(request_context, dict) else None
    method = http.get("method") if isinstance(http, dict) else None
    if not isinstance(method, str):
        method = event.get("httpMethod", "")
    if isinstance(method, str) and method.upper() == "HEAD":
        # Authenticated readiness probes never parse or transmit student evidence.
        return {"statusCode": 204, "headers": {"Cache-Control": "no-store"}, "body": ""}

    body = event.get("body") or "{}"
    if not isinstance(body, str):
        return response(400, {"detail": "Invalid JSON body."})
    if len(body) > ((MAX_REQUEST_BYTES + 2) // 3) * 4:
        return response(413, {"detail": "Request body too large."})
    try:
        if event.get("isBase64Encoded"):
            body = base64.b64decode(body, validate=True).decode("utf-8")
        if len(body.encode("utf-8")) > MAX_REQUEST_BYTES:
            return response(413, {"detail": "Request body too large."})
        payload = json.loads(body)
    except (ValueError, UnicodeError):
        return response(400, {"detail": "Invalid JSON body."})
    if not isinstance(payload, dict):
        return response(400, {"detail": "JSON body must be an object."})

    path = event.get("rawPath") or event.get("path") or ""
    if path.endswith("/verify-id"):
        return verify_id_payload(payload)

    return verify_face_payload(payload)
