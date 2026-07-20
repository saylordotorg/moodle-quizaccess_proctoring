# Webcam phone detection libraries

The "Detect phones in webcam images" feature (site setting `detectphone`) runs
TensorFlow.js COCO-SSD object detection in the student's browser during the
attempt. The libraries and model are loaded from this directory so that no
student data or model traffic leaves the Moodle server.

The feature stays silently disabled until ALL of the following files exist here
(rule.php checks for them before requesting detection):

| File                    | Source                                                                | Notes                                  |
|-------------------------|-----------------------------------------------------------------------|----------------------------------------|
| `tf.min.js`             | npm `@tensorflow/tfjs` (dist/tf.min.js), version 4.x                   | TensorFlow.js bundled browser build    |
| `coco-ssd.min.js`       | npm `@tensorflow-models/coco-ssd` (dist/coco-ssd.min.js), version 2.x  | COCO-SSD detection wrapper             |
| `model/model.json`      | TF Hub `ssd_mobilenet_v2` / `lite_mobilenet_v2` TFJS export            | Plus every `group*-shard*of*.bin` file |

Example (from a machine with npm access):

```
npm pack @tensorflow/tfjs @tensorflow-models/coco-ssd
# copy package/dist/tf.min.js and package/dist/coco-ssd.min.js here
# download the lite_mobilenet_v2 TFJS model files into ./model/
```

The detector only reports the COCO class `cell phone`; frames are analysed in
the browser and never uploaded unless a detection persists across consecutive
checks, in which case one webcam frame is attached to the logged
`phone_detected` event as reviewer evidence.
