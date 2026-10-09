# Packaged-install acceptance

Build a development package with `deployment/package_release.sh --allow-dirty
1.0.0-beta.3`, then start a **fresh** Cacti compatibility stack using the
adjacent `cacti_compat` harness. Use unique container names, state directories,
and ports. The acceptance runner refuses containers without the
`com.cacti.gnmi.test=compatibility` label and nonempty plugin directories.
Use `-f tests/integration/package/native-code.compose.yaml` after the base
compose file for startup and cleanup. Its native plugin volume makes Unix
ownership checks meaningful on Docker Desktop too; delete it only with the
disposable stack (`down --volumes`).

```bash
tests/integration/package/accept.sh \
  dist/cacti-gnmi-plugin-1.0.0-beta.3-dirty.tar.gz \
  dist/cacti-gnmi-plugin-1.0.0-beta.3-dirty.tar.gz.sha256 \
  gnmi_cacti_ci http://127.0.0.1:7083/cacti/
```

The runner validates both checksum layers, executes commands extracted from the
archive's installation guide, and deploys only the archive's runtime. Set
`PACKAGE_INSTALL_MODE=native` to execute the Linux filesystem recipe inside the
disposable Linux host; the default executes the Docker host recipe. Both build
the venv as root at its final path, before any fixture code or Cacti plugin
installation. This tests command correctness in Linux containers, not a separate
bare-metal installation or independent new-user usability study. The runner
adds test fixtures separately, installs the plugin without `--allperms`,
explicitly grants fixture realms, and runs the existing PHP harnesses,
private bridge PHP contracts, exact RRD samples and first graphs, shared
failure/recovery, inherited deadlines and fair resumption, resolver HTTP
refusal, plus authenticated HTTP management acceptance. It then disables,
uninstalls, and reinstalls the plugin, checking daemon and metadata cleanup.
It creates disposable administrative credentials and a restricted test user;
never run it against an existing Cacti deployment.
Code/venv remain root-owned and unwritable by the service UID. Only runtime is
service-owned. HTTP checks use existing nonsecret JSON/key/log probes. Protected
dependency recovery cannot install packages; administrator restore clears the
banner. CI recreates the Cacti container and rechecks ownership and first graphs.
The test entrypoint preserves plugin ownership across recreation. These fixtures
are never shipped in the release archive.

Evidence defaults to `dist/package-acceptance`; set `PACKAGE_EVIDENCE_DIR` to
retain each run separately. A nonzero exit or `result.json` status other than
`passed` blocks acceptance. The archive checksum, source manifest, runtime
versions, and focused test results are retained. No cookies, request bodies,
credentials, or device configuration files are included in evidence.

CI builds and inspects one archive in Repository hygiene, transfers it as an
artifact, and tests those bytes in Cacti 1.2.25 and 1.2.31 PHP harnesses.
The dashboard runner verifies restricted sessions, effective group/graph/device
policies, valid-CSRF forged targets, read-only diagnostics, configured guest/no-auth
behavior, and permission revocation. Cron is stopped in these disposable stacks
to keep mutation-denial state assertions deterministic. Development
archives have a `-dirty` suffix and must never be published.

## Release gate

After the release PR passes protected checks and merges, use a clean checkout
of the chosen `main` commit. Create an annotated local `v1.0.0-beta.3` tag at
HEAD and run `deployment/package_release.sh 1.0.0-beta.3` without
`--allow-dirty`. Keep the resulting archive unchanged throughout acceptance.

1. Run this packaged-install acceptance against a fresh compatibility stack.
2. Install the same archive into the disposable SR Linux Cacti stack without
   invoking source deployment helpers. Complete browser device, subscription,
   metric, data-source, and graph acceptance using `docs/test_plan.md`.
3. Verify independent subscriptions, configuration reload, restart, stale data,
   and recovery from rejected paths. Run `soak-validate.sh --duration 3600
   --interval 60` after recovery. No missing infrastructure or skipped soak
   counts as a pass.
4. Disable, uninstall and reinstall after the soak; verify retained RRD files,
   removed plugin metadata, and stopped daemons. Record Ciena-specific cases
   as not rerun if that target is unavailable.
5. Record source SHA, archive SHA-256, environment versions, actual results,
   and sanitized evidence in the release acceptance summary. Historical
   compatibility claims are not evidence of this candidate's acceptance.
6. Push the annotated tag only after acceptance passes. Publish a GitHub
   prerelease with the exact tested archive, checksum and acceptance summary;
   download the published assets and verify their hashes.

A failed candidate requires a fix through a checked PR and fresh artifact
acceptance. Do not push or replace a public tag for a failed candidate. This
procedure does not validate upgrades or production readiness.

Both tested Cacti versions reset authentication-disabled mode to local
authentication and password repair. The plugin preserves that behavior and
denies explicit mutations from the unfinished password-change session. This
acceptance does not establish an independent no-auth dashboard mode.
