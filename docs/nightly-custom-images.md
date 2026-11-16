# Nightly custom branch images

Custom images and their suites are configured in `configs/nightly.json`:

```json
"custom_images": [
  {
    "image": "ghcr.io/manticoresoftware/manticoresearch:test-kit-index_format",
    "tests": ["hn_small", "hn"]
  }
]
```

`run_nightly.sh` passes each custom image and its selected suites to `run_configured_tests.sh`. The same nightly config supplies their `init` entries, benchmark cases, and shared settings. No second config or inheritance is needed. To run just this image and its selected suites:

```bash
./run_configured_tests.sh -c configs/nightly.json -i ghcr.io/manticoresoftware/manticoresearch:test-kit-index_format -T hn_small,hn
```

For one-off data preparation of the configured custom images:

```bash
./init_custom_images.sh
```

This script only runs the selected suites' `init` entries against each custom image. The normal nightly run initializes them automatically as needed before testing. An empty `custom_images` array disables custom runs. `RUN_NIGHTLY_TAGS` still selects the regular tags (default `latest,dev`); custom images run after them. Custom runs do not invoke the release-vs-dev comparison notification hook. The nightly wrapper writes dated logs and invokes the configured failure hook on errors or skips.

Custom images use separate Manticore data and config paths: `idx<type>_custom_<image-tag>` and `manticore<type>_custom_<image-tag>.conf`. Regular nightly runs continue using `idx<type>` and `manticore<type>.conf`. This also keeps the pre-hook's existing-index check within the selected image's files.

The `suffix` variable remains the index type (`rowwise`, `columnar`, and so on). `MANTICORE_CUSTOM_IMAGE_SUFFIX` is set by the runner and used only for index and config file paths. A custom image tag containing `columnar` cannot change a rowwise test.

For custom images, the result `version` is `<image-tag>-<searchd-commit-date>-<searchd-commit-hash>` rather than the server's potentially unusable `0.0.0`. The actual server version and abbreviated searchd/columnar commit remain in result metadata. The image is pulled before every run; complete results for the same tag/date/commit are skipped for each engine/type/memory/limited/phase combination. An initial result and its retest must both be complete before a suite is skipped. Rebuilding the same commit with different dependencies keeps the same result identity; this policy is based on the source commit, not the Docker image ID.

The runner passes only the custom image reference to the engine plugin. The plugin derives the saved result version independently from that reference and the running server's reported commit and date; no precomputed version is injected.

Testkit images reporting `0.0.0` take the modern (`>=17.0.0`) cache/config capability path without misrepresenting their benchmark result version. Other custom images retain their actual version-based capability checks.
