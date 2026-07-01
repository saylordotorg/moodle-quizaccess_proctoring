# JavaScript interaction tests

These are self-contained JavaScript interaction tests for the plugin's AMD modules.
The plugin does not ship a JS unit-test harness (no `package.json` / Jest / Mocha)
or a Behat suite, so these tests run on a modern Node.js (>= 18) using the built-in
test runner and `assert` module — no dependencies to install.

They load the **real** source from `amd/src/` inside a Node `vm` sandbox with a
mocked DOM and mocked Moodle AMD dependencies, so they exercise the shipped code
rather than a reimplementation.

## Running

From the plugin root:

```
node --test tests/js/
```

Or a single file:

```
node --test tests/js/camera_lifecycle.test.js
```

## Tests

- `camera_lifecycle.test.js` — Camera lifecycle scoped to the Pre-Check modal
  (Requirement 6 / component C6). Asserts `getUserMedia` is not called before the
  modal opens (6.1), is called and bound to the modal `<video>` on open (6.2), and
  that all `MediaStreamTrack`s are stopped and `video.srcObject` is nulled on
  cancel / hide / `pagehide` / `beforeunload` (6.3).

  **Validates: Requirements 6.1, 6.2, 6.3**
