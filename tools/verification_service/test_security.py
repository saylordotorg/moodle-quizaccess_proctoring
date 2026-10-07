"""Offline security regressions; AWS calls are always mocked."""

import base64
import importlib
import json
import os
import unittest
from unittest import mock


with mock.patch.dict(os.environ, {"FACE_API_KEY": "test-secret"}), mock.patch("boto3.client"):
    service = importlib.import_module("lambda_function")


class LambdaSecurityTests(unittest.TestCase):
    def setUp(self):
        service.rekognition.reset_mock()
        service.rekognition.compare_faces.side_effect = None
        service.rekognition.compare_faces.return_value = {"FaceMatches": [{"Similarity": 95}]}

    def request(self, payload, path="/verify-face", key="test-secret"):
        return service.lambda_handler({
            "headers": {"X-API-Key": key}, "rawPath": path, "body": json.dumps(payload),
            "requestContext": {"http": {"method": "POST"}},
        }, None)

    def test_authenticated_head_supports_both_aws_event_formats_without_processing_evidence(self):
        for method_fields in ({"requestContext": {"http": {"method": "HEAD"}}}, {"httpMethod": "HEAD"}):
            for path in ("/verify", "/verify-face", "/verify-id"):
                with self.subTest(method_fields=method_fields, path=path), mock.patch.object(service.json, "loads") as parse:
                    result = service.lambda_handler({
                        "headers": {"x-api-key": "test-secret"}, "rawPath": path,
                        "body": "not valid JSON or base64", "isBase64Encoded": True, **method_fields,
                    }, None)
                    self.assertEqual(204, result["statusCode"])
                    self.assertEqual("", result["body"])
                    self.assertEqual("no-store", result["headers"]["Cache-Control"])
                    parse.assert_not_called()
        self.assertEqual([], service.rekognition.mock_calls)

    def test_head_preserves_authentication_for_both_aws_event_formats(self):
        for method_fields in ({"requestContext": {"http": {"method": "HEAD"}}}, {"httpMethod": "HEAD"}):
            for headers in ({}, {"x-api-key": "wrong"}):
                with self.subTest(method_fields=method_fields, headers=headers):
                    result = service.lambda_handler({"headers": headers, **method_fields}, None)
                    self.assertEqual(401, result["statusCode"])
            with mock.patch.object(service, "API_KEY", ""):
                result = service.lambda_handler({"headers": {"X-API-Key": "test-secret"}, **method_fields}, None)
                self.assertEqual(500, result["statusCode"])
        self.assertEqual([], service.rekognition.mock_calls)

    def test_http_v2_post_method_is_not_replaced_by_legacy_head_field(self):
        result = service.lambda_handler({
            "headers": {"X-API-Key": "test-secret"}, "requestContext": {"http": {"method": "POST"}},
            "httpMethod": "HEAD", "body": "not JSON",
        }, None)
        self.assertEqual(400, result["statusCode"])
        self.assertEqual([], service.rekognition.mock_calls)

    def test_unauthenticated_input_never_reaches_aws(self):
        self.assertEqual(401, self.request({}, key="wrong")["statusCode"])
        service.rekognition.compare_faces.assert_not_called()

    def test_nonobject_json_is_rejected(self):
        for payload in ([], 1, "text", None):
            self.assertEqual(400, self.request(payload)["statusCode"])

    def test_image_type_and_empty_data_are_rejected(self):
        for image in (["abc"], {}, 42, "data:image/jpeg;base64,"):
            result = self.request({"reference_image": image, "current_snap": "YWJj"})
            self.assertIn(result["statusCode"], (400, 422))
        service.rekognition.compare_faces.assert_not_called()

    def test_invalid_thresholds_do_not_reach_aws(self):
        for threshold in (-1, 0, 101, float("nan"), float("inf"), {}, True):
            result = self.request({
                "id_image": "YWJj", "live_image": "YWJj", "face_threshold": threshold,
            }, "/verify-id")
            self.assertEqual(422, result["statusCode"])
        service.rekognition.compare_faces.assert_not_called()

    def test_oversized_body_and_image_rejected(self):
        with mock.patch.object(service, "MAX_REQUEST_BYTES", 32):
            self.assertEqual(413, self.request({"large": "x" * 100})["statusCode"])
        with mock.patch.object(service, "MAX_IMAGE_BYTES", 2):
            self.assertEqual(400, self.request({"reference_image": "YWJj", "current_snap": "YWJj"})["statusCode"])

    def test_valid_face_check_still_works(self):
        result = self.request({"reference_image": "YWJj", "current_snap": "YWJj"})
        self.assertEqual(200, result["statusCode"])
        self.assertTrue(json.loads(result["body"])["match"])
        service.rekognition.compare_faces.assert_called_once()

    def no_face_error(self):
        return service.ClientError({"Error": {"Code": "InvalidParameterException"}}, "CompareFaces")

    def test_reference_without_a_face_is_reported_distinctly(self):
        # Both the real comparison and the reference self-check find no face.
        service.rekognition.compare_faces.side_effect = self.no_face_error()
        result = self.request({"reference_image": "YWJj", "current_snap": "ZGVm"})
        body = json.loads(result["body"])
        self.assertEqual(200, result["statusCode"])
        self.assertFalse(body["match"])
        self.assertEqual("reference_no_face", body["reason"])
        self_check = service.rekognition.compare_faces.call_args_list[1].kwargs
        self.assertEqual(self_check["SourceImage"], self_check["TargetImage"])

    def test_missing_face_in_live_image_is_reported_as_no_face_not_a_mismatch(self):
        # The reference self-check finds a face, so it was the webcam image that had none.
        service.rekognition.compare_faces.side_effect = [self.no_face_error(), {"FaceMatches": [{"Similarity": 100}]}]
        body = json.loads(self.request({"reference_image": "YWJj", "current_snap": "ZGVm"})["body"])
        self.assertFalse(body["match"])
        self.assertEqual("no_face_in_capture", body["reason"])

    def test_mismatch_still_reports_its_similarity(self):
        service.rekognition.compare_faces.return_value = {"FaceMatches": [{"Similarity": 72.456}]}
        body = json.loads(self.request({"reference_image": "YWJj", "current_snap": "ZGVm"})["body"])
        self.assertFalse(body["match"])
        self.assertEqual(72.46, body["similarity"])
        self.assertNotIn("reason", body)

    def test_reference_self_check_outage_never_retires_the_reference(self):
        outage = service.ClientError({"Error": {"Code": "ThrottlingException"}}, "CompareFaces")
        service.rekognition.compare_faces.side_effect = [self.no_face_error(), outage]
        body = json.loads(self.request({"reference_image": "YWJj", "current_snap": "ZGVm"})["body"])
        self.assertNotIn("reason", body)

    def test_base64_lambda_event_body_is_supported(self):
        body = base64.b64encode(json.dumps({"reference_image": "YWJj", "current_snap": "YWJj"}).encode()).decode()
        result = service.lambda_handler({
            "headers": {"x-api-key": "test-secret"}, "body": body, "isBase64Encoded": True,
        }, None)
        self.assertEqual(200, result["statusCode"])


try:
    from fastapi.testclient import TestClient
except ImportError:
    TestClient = None


@unittest.skipIf(TestClient is None, "FastAPI test dependencies not installed")
class BridgeSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with mock.patch.dict(os.environ, {"FACE_API_KEY": "test-secret"}), mock.patch("boto3.client"):
            cls.bridge = importlib.import_module("rekognition_bridge")
        cls.client = TestClient(cls.bridge.app)

    def test_authentication_runs_before_body_parsing(self):
        result = self.client.post("/verify-face", content="not JSON", headers={"content-type": "application/json"})
        self.assertEqual(401, result.status_code)

    def test_streamed_body_is_bounded(self):
        with mock.patch.object(self.bridge, "MAX_REQUEST_BYTES", 32):
            result = self.client.post("/verify-face", content=b"x" * 100,
                headers={"X-API-Key": "test-secret", "Content-Type": "application/json"})
        self.assertEqual(413, result.status_code)

    def test_negative_threshold_is_rejected(self):
        result = self.client.post("/verify-id", json={
            "id_image": "YWJj", "live_image": "YWJj", "face_threshold": -1,
        }, headers={"X-API-Key": "test-secret"})
        self.assertEqual(422, result.status_code)


if __name__ == "__main__":
    unittest.main()
