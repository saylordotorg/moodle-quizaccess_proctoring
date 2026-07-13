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

> Note: on Node >= 20/22 the directory form may not auto-discover files; use the
> glob form instead:
>
> ```
> node --test "tests/js/*.test.js"
> ```

## Tests

- `preflight_waiver_gate.test.js` — Start-attempt preflight gate, waived-step
  omission (Feature `per-student-proctoring-overrides`, Requirement 5). Loads the
  real `amd/src/startAttempt.js` and drives `setup()` to assert that a disabled
  requirement flag omits its Pre_Check step so the gate advances to the next
  enabled step with no error referencing the omitted step (5.2), that a waived
  CAPTCHA/Turnstile is skipped and Start becomes enabled once the remaining
  requirement is met (5.3), and that with all five overridable requirements waived
  Start is reachable after privacy/honor consent — including the fully-waived,
  no-consent case where Start is never locked (5.5).

  **Validates: Requirements 5.2, 5.3, 5.5**

- `camera_lifecycle.test.js` — Camera lifecycle scoped to the Pre-Check modal
  (Requirement 6 / component C6). Asserts `getUserMedia` is not called before the
  modal opens (6.1), is called and bound to the modal `<video>` on open (6.2), and
  that all `MediaStreamTrack`s are stopped and `video.srcObject` is nulled on
  cancel / hide / `pagehide` / `beforeunload` (6.3).

  **Validates: Requirements 6.1, 6.2, 6.3**
