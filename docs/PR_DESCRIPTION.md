## Summary

This PR brings the panel contributed in [PR #89](https://github.com/MadaMzandu/uisp-ros-plugin/pull/89) together with the provisioning, recovery and operational improvements developed since that baseline. Candidate **2.4.46** uses the native RouterOS API, with optional TLS, and makes service changes observable and recoverable through the same queue and ownership checks.

## Resulting behavior

- **Immediate synchronization and observable results.** The primary router action reads current UISP state and applies services immediately, forcing their PPP/Hotspot reconnection. Optional difference review remains a separate action. Readback distinguishes verified application from an applied change still awaiting confirmation; observing again does not replay a write.
- **Durable work and current billing state.** Persistent SQLite jobs retain deduplication, ordered per-service recovery, bounded retries, versions and maintenance exclusion. New webhooks read current UISP state rather than restoring an old event snapshot. Cancellation stops retries while retaining potentially applied resources until reviewed cleanup.
- **Service diagnostics and explicit repair.** Search by service, client, username or IP, then compare UISP, expected policy and observed RouterOS state with job history. Resolve can repair a proven obsolete account marker, create an absent account or consolidate reviewed duplicates. Exact identities, current competing owners, fresh evidence, private backups and single-use permissions guard changes; ambiguous cases retain a specific explanation.
- **Address lifecycle and Site changes.** Freshly confirmed ended services and archived clients retire obsolete intent and schedule guarded cleanup; addresses remain protected while physical resources might still exist. Suspension preserves assignments. Site pool replacement and cross-router moves prepare replacement capacity before cleanup, retain stable allocations across retries and preserve unrelated current owners.
- **Per-service shaping.** UISP overrides use isolated PPP/Hotspot profiles or individual DHCP queues without changing the commercial plan or other subscribers. Burst, thresholds, guarantees and mixed-rate contention use effective service speeds, including unlimited directions. Removing an override restores the base profile and retires the unused owned private profile after reassignment.
- **Allocation-only IP exclusions.** Saving omitted IPv4/IPv6 addresses changes future automatic allocation without bulk provisioning, cache rebuilding or changes to existing assignments. This save preserves work already in the queue. Saves that also change per-service network settings retain their normal application behavior.
- **Operational visibility and backups.** Automation health reports scheduled activity, webhook acceptance, retries and oldest pending work. Gradual difference review corrects only proven cases and leaves ambiguity on the affected service. Pool capacity separates free, assigned, retained and excluded space. Complete private backups include the queue and supported operational state, with reviewed restoration and a pre-restore copy.
- **Compact daily operations.** Responsive Devices, Plans, Settings, Jobs, System and Diagnostics views share search, sorting, six languages and light/dark/system appearance. A notification center holds global notices and recent activity. Resolve shows its real phase, animation and elapsed time while retaining the account context.

## Validation and deployment

Validation for 2.4.46 passed: **70 isolated suites**, syntax checks for **70 first-party PHP files**, 385 archive entries, 152 first-party files and 13 locked dependencies. Report: `dist/verification-2.4.46.json`. SHA-256: `92ad725203ac30f714121f2017b7dec06806ba51cf77e1c0db41eef6362386c4`. The new regression exercises the real panel save route, concurrent writers, unchanged prior work, IPv4/IPv6 reservations, future allocations and mixed settings. No production UISP or RouterOS changes were performed. The real-environment guide contains **293 cases**. Automated checks use synthetic data and simulated or local transports; they do not establish production validation.

The package does not start a router-wide rebuild when installed. Existing overrides can be applied through explicit synchronization or enabled gradual review; subsequent override edits follow normal webhook processing. Existing pending jobs keep their own state rather than being cancelled by an exclusion-only save. Configuration confirmation establishes observed RouterOS properties, not subscriber Internet reachability. Remaining integration checks are documented in the real-environment guide.

Administrative access and webhook authentication remain separate. Webhooks require a shared secret by default; compatibility without that extra secret check is an explicit option. Setup instructions and the complete history are linked below.

## Complete history and review documents

- [Complete English changelog](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/CHANGELOG_EN.md): all release entries and historical validation, preserved in full.
- [Full cumulative PR reference](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/PR_DESCRIPTION_FULL.md): the original complete description, with the English changelog and webhook guide embedded.
- [Webhook setup and rationale](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/WEBHOOK_SETUP_EN.md).
- [Real-environment test guide: 293 cases](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/PRUEBAS_REALES.md).
- [Mejoras acumuladas en español](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/MEJORAS.md).

Historical entries identify behavior superseded by later revisions. The summary above describes the current candidate.
