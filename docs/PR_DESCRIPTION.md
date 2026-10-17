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

## Changes compared with the original plugin

### New Features

- Per-service UISP speed overrides.
- Dedicated override profiles and service-specific shaping.
- Burst presets and shaping previews.
- Optional synchronization previews.
- Apply-differences-only synchronization.
- Post-application RouterOS verification.
- Read-only rechecks without repeating changes.
- Service diagnostics with UISP, expected-state and RouterOS comparison.
- Guided account repair and duplicate resolution.
- Planned job changes with previous → proposed values.
- Automation health dashboard.
- Gradual drift detection and verified automatic corrections.
- Automatic CRM duplicate-IP monitoring.
- Guided CRM IP corrections and historical-assignment review.
- Local reservation repair and cleanup workflows.
- Per-Site IPv4/IPv6 pool capacity reporting.
- Complete backups including configuration, queue, cache and recovery state.
- Reviewed backup restoration with rollback protection.
- Recovery actions for retaining, closing or discarding historical records.
- Router-based recovery of identities lost from legacy cache.
- Centralized notifications and recent activity.
- Light, dark and system appearance modes.
- Operation progress indicators and elapsed time.
- Read-only recovery mode during blocked database migrations.

### Improvements

- Complete compact and responsive panel redesign.
- Reorganized Settings, System, backup and recovery sections.
- Improved search, filtering and column sorting.
- Consistent six-language interface and language selection.
- Persistent drafts, navigation, scroll position and job selection.
- Local SVG icons and updated settings icon.
- Configurable automatic job refresh.
- Nonblocking synchronization with progress per router.
- Direct synchronization with explicit forced reconnection.
- SQLite job queue replacing the legacy JSON queue.
- Transactional import of legacy jobs.
- Persistent job snapshots and per-service versioning.
- Ordered execution, webhook deduplication and bounded retries.
- Recovery after interrupted or partially applied operations.
- Coordination between workers, webhooks and administrative changes.
- Fresh UISP state checks before processing delayed events.
- Atomic IPv4/IPv6 reservations across shared pools.
- Efficient handling of large IPv6 exclusion ranges.
- Safer Site pool replacement and cross-router migration.
- Fresh automatic allocation after clearing an IP attribute.
- Readable profile names with stable ownership and override indicators.
- In-place profile renaming and safe unused-profile retirement.
- Override-aware burst, guarantees, priorities and weighted contention.
- Selective reconnection based on effective changes.
- Protected router removal and shared-resource ownership.
- Atomic cache refreshes and transactional database migrations.
- Improved custom-attribute validation and creation.
- Stronger administrative permissions and same-origin checks.
- Webhook-secret authentication and explicit compatibility mode.
- Expiring, single-use review tokens.
- Credential redaction and actionable operational logs.
- Configurable TLS and certificate validation.
- Updated dependencies, reproducible installers and OPcache handling.
- Expanded regression tests, manual test coverage and cumulative documentation.

### Fixes

- Archived-plan name and speed recovery.
- UISP plan-type and metadata compatibility.
- Invalid numbers, infinities, NaN and calculation overflows.
- Invalid JSON responses caused by numeric corruption.
- Zero contention ratios when contention is disabled.
- Device technology being confused with service filters.
- Incorrect `invalid_service_filter` and `service_site_not_configured` failures.
- Site, PPPoE and optional Hotspot attribute validation.
- Contradictory client/service identifiers in RouterOS comments.
- Incorrect account selection during PPP duplicate resolution.
- Blocked creation after removing an incorrect account.
- Retirement failures after a deleted service disappeared from cache.
- Obsolete-service reservations blocking current assignments.
- Exclusion-only saves generating mass jobs or changing existing IPs.
- Old events restoring outdated suspension or service configuration.
- Retries restoring obsolete shared-profile speeds or parent settings.
- Historical cleanup targeting newer identities or renamed resources.
- Unauthorized changes to manual accounts, profiles, queues or pools.
- Premature IP release during incomplete cleanup.
- Lost resources after canceled or interrupted migrations.
- Missing or partially created suspension profiles.
- Duplicate or incorrectly targeted PPP/Hotspot disconnections.
- IP exports overwriting deliberate CRM changes.
- DHCP/DHCPv6 MAC, DUID, IAID and pool-handling errors.
- Orphan-account audit result handling.
- Partial events erasing omitted fields or client names.
- Numeric-looking credentials and leading-zero preservation.
- Blank password edits overwriting existing credentials.
- One invalid service stopping an entire synchronization.
- Failed refreshes hiding original job results or errors.
- Failed router reads being treated as empty successful responses.
- RouterOS framing, authentication, EOF, timeout and socket-cleanup errors.
- Modal flickering, blur issues and unnecessary loading states.
- Stale asynchronous responses replacing current settings or drafts.
- SQL parameterization, dynamic-content escaping and private-error exposure.
- Cached frontend and PHP code mismatches after upgrades.

## Validation

**73 isolated suites passed**, plus syntax checks for **72 first-party PHP files** and installer/dependency checks. The verified ZIP is `src/ros-plugin.zip`; report: `dist/verification-2.4.48.json`. Tests cover ownership transfers, retries, cleanup, overrides, read-only job summaries and safe errors. No production UISP or RouterOS changes were performed; the integration guide contains **305 manual cases**.

Installation does not trigger a router-wide rebuild. Existing overrides require explicit sync or enabled gradual review. Router configuration readback does not establish subscriber Internet reachability. Administrative and webhook authentication remain separate; webhook secret setup is in the full reference.

## Complete details

- [Full changelog](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/CHANGELOG_EN.md).
- [Cumulative reference and webhook setup](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/PR_DESCRIPTION_FULL.md).
- [Real-environment test guide](https://github.com/dhanielsnieto/uisp-ros-plugin/blob/main/docs/PRUEBAS_REALES.md).
