## Summary

Combine the panel from [PR #89](https://github.com/MadaMzandu/uisp-ros-plugin/pull/89) with provisioning and recovery improvements in **2.4.48**, using the native RouterOS API with optional TLS.

## Essential improvements

- **Immediate sync and verified results:** synchronize directly, with optional difference review as a separate action. Readback distinguishes verified application from changes awaiting confirmation.
- **Persistent jobs and current state:** ordered retries, deduplication and recovery survive restarts. Fresh UISP checks prevent delayed events from restoring obsolete configuration.
- **Diagnostics and repair:** search by client, service, username or IP; compare UISP, expected policy and RouterOS. Review exact account ownership before repairing associations, creating missing accounts or resolving duplicates.
- **Address lifecycle:** retire obsolete work for ended services and archived clients; release IPs after guarded router cleanup. Preserve suspended assignments, handle Site/pool changes and cross-router moves, and expose pool capacity. Saving excluded IPs affects future allocation without bulk jobs or changes to current IPs.
- **Per-service overrides:** isolated PPP/Hotspot profiles or DHCP queues apply effective speed, burst, guarantees and contention without changing other subscribers. Profiles retain the base plan name plus `[Override S#ID]`; removing an override restores the shared policy.
- **Operations panel:** compact responsive views, six languages, light/dark themes, notifications and visible progress. Automation health, gradual difference detection and complete backups include the queue and reviewed recovery.

## New in 2.4.48

- **Planned changes in Jobs:** expandable previous → proposed IP, account, router, profile, state and shaping values, including burst, guarantee, priority and contention. Show requested reconnections and unknown values explicitly. Comparison uses stored applied state; historical deletion shows its own saved target. Listing jobs performs no external reads or writes.
- **Recoverable service retirement:** validate cleanup before enabling retirement. Recover purged legacy identities only from exact client/service ownership on the recorded router. Explain running jobs, changed ownership, expired reviews and unconfirmed cleanup; keep internal errors private and never replay an uncertain write automatically.

## Validation

**73 isolated suites passed**, plus syntax checks for **72 first-party PHP files** and installer/dependency checks. The verified ZIP is `src/ros-plugin.zip`; report: `dist/verification-2.4.48.json`. Tests cover ownership transfers, retries, cleanup, overrides, read-only job summaries and safe errors. No production UISP or RouterOS changes were performed; the integration guide contains **305 manual cases**.

Installation does not trigger a router-wide rebuild. Existing overrides require explicit sync or enabled gradual review. Router configuration readback does not establish subscriber Internet reachability. Administrative and webhook authentication remain separate; webhook secret setup is in the full reference.

## Complete details

- [Full changelog](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/CHANGELOG_EN.md).
- [Cumulative reference and webhook setup](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/PR_DESCRIPTION_FULL.md).
- [Real-environment test guide](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/PRUEBAS_REALES.md).
