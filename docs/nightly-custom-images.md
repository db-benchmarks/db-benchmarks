# Nightly custom branch images

Run a single branch image against the nightly suite:

```bash
./run_configured_tests.sh -c configs/nightly.json -i ghcr.io/manticoresoftware/manticoresearch:test-kit-light-perf_improvements
```

To include custom images in the scheduled latest-then-dev run, edit the `custom_images` array in `configs/nightly.json`:

```json
"custom_images": [
  "ghcr.io/manticoresoftware/manticoresearch:test-kit-light-perf_improvements"
]
```

The example image is configured by default. Add or remove references in the array; an empty array disables custom runs. `RUN_NIGHTLY_TAGS` still selects the regular tags (default `latest,dev`); configured custom images run after them. Custom runs do not invoke the release-vs-dev comparison notification hook. The wrapper writes dated logs and invokes the configured failure hook on errors/skips.

For custom images, the result `version` is `<image-tag>-<searchd-commit-date>-<searchd-commit-hash>` rather than the server's potentially unusable `0.0.0` (for example, `test-kit-light-perf_improvements-26102015-5ba603a55`). The actual server version and abbreviated searchd/columnar commit remain in result metadata. The image is pulled before every run; complete results for the same tag/date/commit are skipped for each engine/type/memory/limited/phase combination. An initial result and its retest must both be complete before a suite is skipped. Rebuilding the same commit with different dependencies keeps the same result identity; this policy is based on the source commit, not the Docker image ID.

The runner passes only the custom image reference to the engine plugin. The plugin derives the saved result version independently from that reference and the running server's reported commit and date; no precomputed version is injected.

Testkit images reporting `0.0.0` take the modern (`>=17.0.0`) cache/config capability path without misrepresenting their benchmark result version. This has been checked with `searchd --check` for the example image. Other custom images retain their actual version-based capability checks.
