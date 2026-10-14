# UISP ROS Plugin — cumulative changelog

[Español](MEJORAS.md) · [PR description](PR_DESCRIPTION.md) · [Webhook setup and rationale](WEBHOOK_SETUP_EN.md)

This revision combines [dhanielsnieto's changes in PR #89](https://github.com/MadaMzandu/uisp-ros-plugin/pull/89) with the subsequent fixes prepared in this repository. The comparison starts at `7ca8dc8`; the original PR ends at `056b89f`. The current candidate is **2.4.22**. The operator confirmed archived-plan speed recovery in their 2.4.19 installation. That confirmation covers this historical scenario; the remaining UISP and RouterOS integration scenarios still require their own checks before production readiness can be established.

The plugin connects UISP CRM to MikroTik through the **native RouterOS API**, normally on port `8728`, or `8729` with TLS. The panel manages that integration; it does not switch the transport to SSH, WinBox, or REST.

## Candidate 2.4.22: reviewed cleanup of obsolete IP reservations

The warning counts **saved assignments**, rather than asserting that a customer has multiple current packages. A service removed from CRM can retain a local reservation. Stored service states explicitly say “saved data”, records without a local service are identified as IP records, and checking another owner preserves earlier verification results.

**IP conflicts → Review cleanup** previews a bounded selection and explains protected entries. A candidate requires an explicit HTTP 404 for its CRM service and a valid current peer service in the same conflict. Every existing service is preserved, including ended services and services belonging to archived clients. Transport/authentication failures, malformed responses, missing verified peers, pending work, unresolved recovery, and applied ownership recorded in the queue block removal.

Confirmation repeats the CRM checks, rejects changed local assignments, and creates a separate private, downloadable SQLite backup before editing. The transaction removes only selected obsolete reservations and clears their legacy address fields to prevent reimport. Local service records, other owners, configuration, CRM, routers, cache, and jobs remain intact. Existing maintenance exclusion is acquired without initializing or importing the queue. Any failed prerequisite or backup aborts the whole selection.

The result provides the backup and remaining conflict count. The backup remains downloadable from **System** after a reload, with administrative edit permission. Reload completes migration when no duplicate assignments remain; other writes stay paused while conflicts persist. A standalone 404 never triggers automatic cleanup: an administrative preview and explicit confirmation are required.

**2.4.22 validation passed:** 32 isolated suites, syntax checks for 44 first-party PHP files, and installer verification covering 356 entries, 123 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.22.json`. SHA-256: `0c92a93035807b0c8306971820353ac7d1f1631600f6713fb621d52a04480c90`. Browser checks also covered preview, current-service protection, and confirmation with synthetic data. Installation and cleanup in the real UISP instance remain operator actions; these checks perform no production cleanup.

## Candidate 2.4.21: panel consultation during a paused upgrade

- Replaces the standalone 2.4.20 warning with the usual panel. All six views—Devices, Plans, Configuration, Jobs, System, and IP conflicts—remain accessible while duplicate addresses prevent migration, with a persistent amber indicator, count, and view-mode notice. Global uniqueness and the provisioning pause remain enforced.
- `ApiPanelRecovery` returns the panel GET with HTTP 200 and a safely encoded JSON bootstrap. `ApiRecoveryReads` handles only an explicit allowlist of administrative reads, opens SQLite with `SQLITE3_OPEN_READONLY`, and projects legacy schemas in memory. It does not initialize/import the queue, write cache data, probe routers, or fetch CRM metadata automatically.
- While migration remains blocked, settings and devices/plans cannot be changed, jobs cannot be executed/deleted, backups cannot be created/restored, and rebuild/provisioning actions remain paused. Views show stored snapshots. Downloading existing backups retains the administrative edit permission previously required for downloads.
- `ApiNetworkDiagnostics` adds client names and local status with their source, together with same-origin client (`/crm/client/{id}`) and service (`/crm/client/service/{id}`) links. IDs are validated and text is escaped. Detailed diagnostics remain administrator-only; webhooks receive only the error code and conflict count.
- **Verify in UISP** explicitly reads the selected service and its client. Each request accepts up to three services present in the diagnostic and performs at most six CRM reads, with a 12-second budget for starting further reads. This is a soft budget: a request already in flight can finish later. Verification does not update cache, queue, reservations, or routers.
- Client archival uses the official `isArchived` field. An ended service (`2`) or inactive service (`8`) does not establish that its client is archived or deleted. Missing cache records, failed reads, and HTTP 404 remain unconfirmed and preserve known information. Stale reservations can explain a conflict but are never released or reassigned automatically.
- Downloading or rechecking the diagnostic does not repair assignments. The operator must compare them with CRM/router state, explicitly resolve the conflict, and complete migration before writes resume. Documentation and fixtures use synthetic identities and data.

**2.4.21 validation passed:** 31 isolated suites, syntax checks for 43 first-party PHP files, and installer verification covering 355 entries, 122 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.21.json`. SHA-256: `ecc819f6f6d247ae28b1b181292a75fe836e79c06596cc09c0727068cde8786a`. Browser checks also verified navigation and the warning with synthetic data. This revision still requires UISP integration verification and explicit reservation resolution in the affected installation.

## Changes introduced in 2.4.20: address conflict diagnostics before migration

This revision's standalone warning is superseded by 2.4.21 view mode. Its report, hash, and rejection behavior remain recorded as historical evidence.

- Service IPs must remain globally unique, including across different routers. An upgrade still rejects a legacy database containing the same IPv4 address or IPv6 address/prefix for multiple services, preserving those assignments for explicit review.
- The authenticated panel shows a Spanish warning with an indicator and conflict count, a family/address/service-ID table, a JSON diagnostic download, and a recheck action. Both reported groups and service IDs are bounded; credentials are excluded.
- POST requests retain JSON with the specific `duplicate_network_addresses_resolve_before_upgrade` error. Address and service-ID details are restricted to administrators; webhooks receive only the code and conflict count. Administrative permissions and webhook authentication remain enforced.
- Read-only preflight runs before queue initialization and backup rotation, repeats under setup locks, and is checked again inside the migration transaction. It includes the projected import from legacy `services.address` and `services.prefix6` columns, preventing `INSERT OR IGNORE` from silently discarding a conflicting assignment.
- Installation remains paused until the assignments are explicitly corrected and rechecked. The warning preserves existing IPs and sends no RouterOS account or session changes; it does not delete rows, reassign addresses, or repair conflicts automatically.

**2.4.20 validation passed:** 29 isolated suites, syntax checks for 41 first-party PHP files, and installer verification covering 353 entries, 120 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.20.json`. SHA-256: `fd74d7d8b81c8937a8553e20305e4bcdbc743cf6b19d7f34401e7956dfda7a01`. Tests use temporary databases and simulated/local transports; the warning and explicit assignment resolution still need verification in Familynet.

## Candidate 2.4.19: Internet type validation and metadata diagnostics

- Fixed the case-sensitive comparison introduced in 2.4.18, which expected `internet` and rejected the `Internet` value returned by UISP. The comparison now accepts both spellings while continuing to reject general service plans and malformed types.
- Updated catalog and plan 38 recovery tests to use `Internet`, covering both accepted spellings, rejected types, and diagnostics identifying the failing field.
- Recognizes the documented `archived` field while retaining compatibility with `isArchived`. The [official Apiary schema](https://jsapi.apiary.io/apis/unmscrm) distinguishes the `internet` filter from the `Internet`/`General` response values and specifies speeds in Mbps. Archived plans are still fetched by their exact ID.
- Metadata failures emit `plan_metadata_failed` with the plan ID, reason, and field, even with debugging disabled. Response bodies and arbitrary exception messages are excluded. The previous catalog is retained, and no invented speeds are applied.
- In the operator's 2.4.18 test, seven of eight services on router 2 completed; service 814 failed on plan 38 metadata, and the generic log did not identify the field. After testing 2.4.19, the operator confirmed on October 11, 2026 that archived-plan speed recovery works correctly.

**2.4.19 validation passed:** 28 isolated suites, syntax checks for 40 first-party PHP files, and installer verification covering 352 archive entries, 119 first-party files, and 13 dependencies. Report: `dist/verification-2.4.19.json`. SHA-256: `9be1f9102bf835f04e9bcc9e7c459bc567555425ce27763fe06341ed02b7118f`. The first run timed out while starting PHP for the local HTTP test; the isolated rerun and subsequent full verification passed. The initial report is retained at `dist/verification-2.4.19-first-run.json`. Archived-plan speed recovery also has operator confirmation from a real installation; other scenarios in the test matrix remain subject to separate validation.

## Changes introduced in 2.4.18: referenced archived plans

- Fetches `GET service-plans/{id}` when a positive plan ID lacks usable metadata, including archived plans omitted from the usual collection. This does not rely on an unverified archive filter or require opening Plans first.
- Restores the name and base speeds without overwriting local overrides, percentages, burst settings, or priorities. Managed profiles retain their identity, and accounts preserve their frozen IP addresses and credentials.
- Includes archived plans referenced by services in the list. A failed fetch retains the entire previous catalog. Plan failures during synchronization are reported per service, without creating a profile from fallback data. Removing accounts does not require a UISP lookup.
- Added an in-memory RouterOS transport test covering archived plan 38, renaming the existing profile, 5/25 Mbps, overrides, atomic catalog updates, prepared retries, and bounded failure handling.

**Historical 2.4.18 validation passed:** 28 isolated suites, syntax checks for 40 first-party PHP files, and installer verification covering 352 entries, 119 first-party files, and 13 dependencies. Report: `dist/verification-2.4.18.json`. SHA-256: `2a0c4da46b426e7fabf38f2ec642d9a5de3be927fb063c4a9a20b0e6bc260c21`. Real UISP/RouterOS integration validation was still pending at that stage; the subsequent production-environment observation and correction are recorded under 2.4.19 above.

## Changes introduced in 2.4.17: cancellation and later recovery

- **Delete cancels all pending jobs**, including prepared, attempted, and imported jobs. Running jobs remain protected. Cancellation stops retries without sending commands or creating jobs; it does not undo changes that may already have reached RouterOS.
- Potentially applied resources retain an exact private record of their original identities, addresses, commands, and targets, together with their reservations. A partially created account on another router is not forgotten when a job is cancelled or the worker restarts.
- Only a new service change or a later full synchronization initiates cleanup. Before applying the new configuration, the original resources are removed with ownership checks. Cancelled update commands are never replayed. If a target fails, the new job and recovery record remain pending, and reservations are not released early.
- Queue-only operations do not consume that recovery record. A cancelled removal preserves protection for its replacement and can also cancel dependent pending successors. Resending a cancelled UUID does not execute its operation again.
- Evidence of partially creating a suspension profile is preserved. Cleanup verifies an existing profile before changing accounts or sessions; mismatched frozen values remain an error. A missing profile is not recreated during cleanup, and an existing profile is not overwritten.
- Connection handling is unchanged: each service batch opens and closes its own API connection; queries and cleanup can open additional connections. API login/logout messages alone do not mean PPP sessions were disconnected. No connection sharing between batches was introduced.

The historical 2.4.17 validation passed 27 suites, checks for 39 first-party PHP files, and verification of a ZIP containing 351 entries, 118 first-party files, and 13 pinned dependencies. Its report and hash are retained later in this changelog; the validation status of current candidate 2.4.22 is recorded above.

## Changes contributed in PR #89

- **New panel:** an HTML, CSS, and JavaScript interface for devices, plans, configuration, jobs, and system tools, with less dependence on the previous frontend.
- **Search and sorting:** device and plan filters by name/ID, plus column sorting.
- **Administrative access:** UISP session validation at the entry point. The initial source-address authorization for webhooks was subsequently replaced by the secret mechanism documented below.
- **Client queries:** numeric ID validation as the initial SQL injection fix. Current queries use prepared parameters.
- **Valid JSON:** recursive handling of `INF`/`NaN` and serialization failures prevents an invalid response from breaking the panel.
- **Visible errors:** structured server error responses. The current implementation logs details internally without exposing them to the browser.
- **Orphaned account audit:** fixed the inverted API-result condition and normalized MAC addresses and RouterOS addresses; the audit can be run per device.
- **Archived plans:** excluded from the usual list while retaining information required by existing services.
- **Configuration language:** manual selection of field metadata language in addition to the browser's language.
- **Custom attributes:** checking for and creating missing attributes from the panel.
- **System information:** plugin/PHP versions and cache maintenance timestamps.

## Interface and daily use

Configuration is organized into functional sections with responsive columns. Several fields share a row on desktop and collapse into one column on small screens. Help expands on demand, and save actions remain accessible. Device and plan forms, tables, and utilities follow the same layout conventions.

- **Dark, light, and system themes**, with the preference saved in the browser. System mode follows operating-system appearance changes.
- **Spanish, English, Portuguese, French, German, and Italian** for navigation, forms, help, actions, notifications, and the burst editor.
- Panel appearance and language are local preferences: changing them does not save plans or alter global configuration.
- Drafts survive tab/view changes and asynchronous requests. Changing the language preserves unsaved values.
- Switches for Boolean options, single-button common actions, and notifications that do not block the page.
- Confirmation for destructive actions; dynamic values are escaped before insertion into HTML.
- Local SVG icons remove the need to download icons from a CDN.
- Fixed request routing and validation for create, edit, and delete actions. Leaving a password blank while editing preserves the existing credential.
- The plan editor supports older cached pages and retains manual controls when template HTML is missing.
- Fixed the ratio `0` blocker when contention is disabled: the field is disabled and omitted from the request. With contention enabled, the ratio must be an integer of at least `1`.
- Modals open without activating the network loader. Removed blur that caused rendering problems in the affected browser; the operator confirmed that flickering stopped after installing 2.4.5.
- Legacy controls without an effective implementation (`hs_enable`, `unsuspend_date_fix`, `unsuspend_fix_wait`) are hidden while their historical values are preserved.

## Plans, speeds, burst, and contention

The editor exposes normal speed, guaranteed minimum, priority, burst increase, threshold, and averaging window separately for upload and download. A speed overridden in the plugin is now used for both the normal limit and burst calculations; previously the normal limit could ignore the override.

| Preset | Maximum burst | Threshold relative to normal speed | Window |
| --- | --- | --- | --- |
| No burst | Disabled | Disabled | 0 s |
| Gentle | 125% | 80% | 8 s |
| Balanced | 150% | 75% | 12 s |
| Fast | 200% | 70% | 10 s |

Selecting a preset fills in the form. Only **Save** starts applying it to services. Values remain editable in both directions.

The preview shows normal, burst, and threshold speeds in Mbps and explains each preset. The window is the period used to measure average traffic, **not the burst duration**. After idle time, an initial estimate is `threshold × window / burst speed`; recent traffic and parent queues affect the result. Presets are plugin suggestions, not performance guarantees. [MikroTik documentation](https://manual.mikrotik.com/docs/firewall-and-quality-of-service/queues/queue-burst/).

Contention groups services for a plan/router under parent queues. A ratio of `1` does not reduce the aggregate through sharing; a larger divisor configures the corresponding contention ratio. Disabling contention schedules jobs to remove service links to those parents. Unused older parents may remain; automatic removal is not guaranteed.

## Automation and recovery

- **Persistent SQLite queue:** replaces the fragile `queue.json` mechanism, imports legacy jobs transactionally, and retains the original file if import fails.
- **Snapshots:** each job preserves the router, identity, addresses, configuration, and commands it needs. Later changes do not silently rewrite a pending operation.
- **Per-service versions:** an old intent must not replace a newer one. Fixed the `A → B → A` sequence and cleanup of previous identities.
- **Deduplication:** repeated service-webhook UUIDs retain minimal receipts to prevent reapplying the same delivery.
- **Retries:** increasing backoff from 30 seconds to one hour when RouterOS is unavailable.
- **Complete confirmation:** a service is marked applied, and previous resources are released, only after all commands are confirmed. Partial failures remain recoverable.
- **Idempotent deletions:** an already absent resource does not turn a deletion retry into a permanent failure.
- **Service and client locks:** serialize webhooks, name changes, workers, and plan/configuration changes. Cron and web PHP share the same lock files.
- **Application journal:** saving plans or configuration records affected pending services in the same transaction as the change. Preparation reads the current billing state and resumes when a service was busy.
- **Exclusive restore:** restoration cannot overlap a worker or an administrative write.
- **Durable terminal state:** terminating or deleting a service records the intent together with its cleanup job. Saving plans or configuration, or rebuilding from an old cache, cannot recreate it while the router is offline. A new explicit UISP activation permits provisioning again.
- **Legacy job recovery:** recognizes pending, completed, and receipt-only terminations from the previous queue. Migration replacements from the same event are distinguished from termination. If an old update cannot establish a new UISP intent, it stays pending until a new service event arrives.
- **Protected router removal:** blocked while services, pending operations, or applied state still reference the router.
- **Partial events:** omitted fields preserve their values; explicit `null` retains its meaning. An incomplete new service must be hydrated or fail without being published as complete.
- **Literal credentials:** values such as `0` and `00123` remain strings rather than becoming numbers or disappearing through falsy checks.
- **Reduced history:** completed jobs retain a summary without commands or credentials and are purged after 30 days. Pending jobs and applied state retain the data required for recovery.
- **Local cancellation:** cancelled pending jobs stop retrying, but possibly applied resources remain privately recorded until cleanup is confirmed following a later intent. Preserving `jobs.db` is necessary for that recovery.

## RouterOS and resource ownership

- Managed active profiles use `Full plan name [UISP#ID]` and readable comments such as `Plan #94`; services without a real plan use an individual profile. Historical `uisp-ros-plugin:plan:ID` comments and `uisp-plan-ID` technical names remain recognized for migrating older profiles and snapshots.
- Managed profiles are located through ownership and identity checks, then renamed in place using the same `.id`; the result is verified before accounts are updated. A readable comment alone does not authorize adoption of an external profile. Names occupied by external profiles and duplicate ownership markers produce conflicts. Historical deletions retain their original names.
- Ownership is verified before modifying or removing reserved plugin suspension profiles and limits.
- PPP/Hotspot accounts, DHCP/DHCPv6 leases, and individual queues require matching client and service ownership before modification, conversion to static, or deletion. Historical comments in the exact `CID - name - SID` format are accepted; an isolated number in a comment is insufficient.
- Static records created manually or by another service produce a visible conflict. Only an uncommented dynamic lease with matching MAC or DUID/IAID may be adopted during create/update; deletion does not adopt it.
- PPP/Hotspot disconnects first verify ownership of the main account. A batch is validated before creating resources associated with another owner's account.
- New parent queues use `uisp-plan-ID-parent` with the `uisp-ros-plugin:plan-parent:ID` ownership marker. Older `servicePlan-ID-parent` queues are retained; their limits and position require lab validation.
- Retries preserve individual account details, IP addresses, and credentials, while reading current plan/configuration values for shared profiles and parent queues under router locks. An old job no longer restores another client's older speed.
- Parent queues include current addresses and addresses still applied or prepared, including aliases of the same endpoint and IPv4/IPv6. Each service is counted once, and terminal cache entries cannot reintroduce removed accounts.
- Managed IPv6 pools use names versioned by prefix/size and an ownership marker. An old job retains its pool and binding without overwriting another plan's new pool. The historical `uisp-pool` is not modified or deleted automatically.
- Moving a service to another client cleans up the previous owner's identity before creating the new one.
- Pending commands from older releases are migrated to avoid modifying external profiles solely because their names match.
- An existing PPP/Hotspot suspension profile **is used without being overwritten**. Since 2.4.15, a missing profile is created with the configured address list and limit. Router firewall rules must enforce the intended access policy for that list.
- The additional PPP/Hotspot suspension limit uses an individual `uisp-service-ID-suspension` queue placed first among simple queues. Reactivation removes this additional limit.
- Failed reads and disconnects no longer appear as successful empty lists. Sockets close even when an exception occurs.
- Fixed binary API framing, EOF handling, timeouts, traps, and fatal responses.
- Configurable API TLS; port `8729` implies TLS for legacy records without an explicit option. Certificates and hostnames are verified; self-signed certificates require an explicit opt-in.
- Fixes for PPP/Hotspot sessions, normalization of device types, and DUID/IAID handling, including IAID `0`.

An upgrade migrates an account when its job is processed. **Installing the package does not automatically rebuild every router.** Managed technical profiles from 2.4.8 are renamed when processed. Historical profiles without an ownership marker are not automatically adopted or deleted. Reference and session preservation during renaming still requires validation on real RouterOS.

## Fixes following 2.4.8 testing

`invalid_cached_address` and `Inf and NaN cannot be JSON encoded` identify different problems. A service without a site may store another provider's information for reference only; those attributes are preserved and do not authorize provisioning.

| Failure | Behavior corrected in 2.4.9 |
| --- | --- |
| Informational IP addresses on services without a site entered reservations and generated warnings. | Only attributes of services with a configured router and a manageable status participate. Applied/prepared reservations remain held until cleanup is confirmed. |
| Adding a site to an informational service attempted migration from a nonexistent router. | Direct creation when no previous managed identity exists; credentials are not generated automatically without a site. |
| Removing a site or terminating a service could leave an identity partially created during migration. | All applied/prepared identities are cleaned up in order, retaining their addresses until cleanup finishes. |
| SQL calculations for speeds, percentages, or contention could produce `INF`, `NaN`, or an overflow-derived `NULL`. | Validate original values, calculations, Mbps/bps conversion, and integer ratios; report the service, plan, and field without converting the error into zero speed. |
| A partial edit could save finite values whose calculations overflowed. | Validate the effective row within the transaction; rejection preserves the previous row and creates no new requests. |
| One invalid service stopped the rest of a synchronization. | Valid services continue; the response includes a partial summary and persistent notification. Diagnostics are preserved in HTTP 502 responses as well. |
| Profiles displayed only `uisp-plan-ID`. | Use the full plan name plus a stable ID; retries follow the current name without changing the frozen identity, IP address, or credential. |

A **manual IPv4 address contains only the client's IP**, without `/30`, `/32`, or any other mask. The plugin does not convert an informational CIDR attribute into a managed IP. When a site is assigned, the manual attribute must be corrected or left empty to use the router/global pool according to configuration. Pool prefixes and IPv6 attributes retain their own handling.

Subsequent 2.4.10 logs confirmed `limitUpload` with `reason=non_finite`, `stage=source`, and `value=Infinity` in three plans affecting five services. The invalid value was already stored in the plugin; the diagnostic does not establish which historical operation produced it. Recovery consists of reviewing the plans, explicitly correcting or resetting their invalid settings, and saving. The operator later reported successful synchronization; that confirmation does not replace suspension/reactivation tests or checks of every service.

## Findings from the second review

### Plan diagnostics in 2.4.10

- Empty or whitespace-only optional numeric fields are treated as unconfigured before SQL calculations. They no longer become an unintended 100% guarantee, and reading a plan does not modify its stored row.
- The plan list identifies invalid fields before JSON serialization. A stored infinity no longer appears as a seemingly valid `0` in the editor.
- The editor requires explicit correction of affected fields. Resetting invalid settings changes the draft; only Save applies it. Base speeds must be reviewed in UISP.
- Diagnostics include `serviceId`, `planId`, `field`, `reason`, `stage`, and a safe representation of the value. Range, integer, and overflow checks remain in place, while valid services continue processing.
- A regression reproduces valid 5/25 Mbps base speeds with a corrupt `limitUpload`. Subsequent production logs confirmed an infinity stored in that percentage. Archiving a plan does not remove its local record or invalidate its subscribers; the historical operation that produced the infinity remains undetermined.

| Reproduced failure | Fix |
| --- | --- |
| The first event for a client outside the cache failed because the SDK returned an object. | Normalize the response and preserve names omitted from the event. |
| A matching username, MAC, DUID, or queue name could allow changes to a manual account or another service's account. | Verify ownership before mutations and disconnections. |
| An external parent queue with a matching name could be modified. | Use an owned namespace, an ownership marker, and reference migration. |
| A terminated service with an offline router reappeared after saving a plan or configuration. | Persist terminal intent, filter automatic updates, and require subsequent explicit activation. |
| Termination jobs from the previous version lacked durable terminal state. | Recover it from pending jobs, completed jobs, and historical receipts, treating ambiguous states conservatively. |
| Retrying one subscriber restored stale shared profiles or parent queues and affected other subscribers. | Read current shared resources under lock while preserving the job's original account snapshot. |
| A DHCPv6 retry could overwrite another plan's newer pool. | Use revision-specific, owned pools and adapt old references without modifying external pools. |
| `Accept-Language: es-419` lost Spanish, and `q` priorities were ignored. | Parse numeric subtags and priorities, excluding `q=0`. |
| A slow Attributes response could revert the latest General selection. | Discard stale responses and preserve the controls and drafts of the most recently selected view. |

Reproductions and regressions are in `tests/regression_second_audit.php`, `regression_ownership.php`, `regression_shared_resources.php`, `regression_shared_pool.php`, `regression_locale.php`, and `regression_panel.js`. Conflicts with manual accounts require explicit resolution on the router: a matching name does not grant the plugin ownership.

## Addresses, cache, and database

- Atomic IP reservations account for applied services, pending jobs, and migration operations, preventing duplicate assignments.
- IPv4/IPv6 handling uses GMP, router prefixes, and manual addresses without inheriting the allocation size of a previously processed service.
- Excluded ranges are sorted and merged. A large IPv6 exclusion is skipped as an interval instead of iterating through millions of prefixes.
- An invalid cached address blocks its own service and preserves existing reservations; it does not silently invalidate every service.
- Cache downloads are staged separately and published only after a complete download with no concurrent changes.
- SQL migrations are transactional, queries use prepared parameters, and partial edits preserve omitted columns and fields.
- Unique-index creation detects address conflicts without automatically deleting reservations.
- SQLite backups are checked for header, integrity, schema, and version before replacing the active database; restoration updates the open database handles.
- Versions remain strings. Databases and backups newer than the installed code are still rejected, with database, runtime, and package versions included in the diagnostic.

## Suspensions, reconnections, and jobs in 2.4.11

- Earlier synchronization disconnected PPP/Hotspot sessions unconditionally. Version 2.4.11 introduced comparison of account/profile properties against the applied snapshot and a RouterOS read, preserving sessions when synchronization was identical. Effective changes, missing prior references, and removals retained the required reconnection; configuration writes could still occur when disconnection was skipped. **The manual router button's policy was subsequently changed in 2.4.13, as described below.**
- Reconnection intent is persisted before any write. If an attempt fails after updating a profile, its retry retains the pending reconnection. A durable revision per profile/router lets later services detect a shared repair even after the first service has corrected the profile. The local address of an owned profile without a static IP remains stable; renaming the same owned profile does not by itself require reconnection.
- An `edit` could be lost when `cache_sync()` had already published the new UISP state and no distinct previous state arrived. An additional comparison against the actually applied state detects suspension/reactivation and changes to plan, identity, credentials, and network settings. Partial events without an explicit status retain the pending termination barrier.
- Jobs refreshes its rows after failed execution or deletion and shows status, attempts, next retry, and last error. If the follow-up query also fails, the original notice remains and the panel reports that the table could not be refreshed.
- An operator-provided delivery returned HTTP 403 `unauthorized` before a job was created or the router accessed. The previously introduced secret requirement also needed configuration in the UISP webhook endpoint. The displayed URL contained no token; headers were not provided, so that evidence alone could not distinguish an absent token from an incorrect one. Logs now distinguish `webhook_secret_not_configured`, `webhook_token_missing`, and `webhook_token_mismatch`, using minimal metadata without customer data or credentials.

To correct a 403 delivery, configure a random value of at least 32 characters under **UISP plugin configuration → Webhook secret**, then send the same value in the endpoint URL (`public.php?webhookToken=THE_SAME_SECRET`). The receiver also accepts `X-Webhook-Token` and Bearer authentication when the sender supports them. Check a new delivery and its outcome in Jobs/RouterOS. Configuring only the plugin, or installing the ZIP, does not repair an existing endpoint's credentials automatically.

## Reconnection and related events in 2.4.13

- At the operator's request, the device button becomes **Synchronize and reconnect accounts**. Every use synchronizes the selected router's managed accounts and forces their PPP/Hotspot sessions to close after updating each account. The confirmation and result explain this behavior in all six panel languages.
- Explicit `suspend`, `unsuspend`, `suspend_cancel`, and `activate` events also carry a reconnection request. A completed UUID does not execute it again.
- The request is persisted before commands are prepared, survives failures and restarts, and is removed from the applied state on completion. It does not permanently change the policy for other updates.
- The observed repeated writes corresponded to the billing event and its accompanying `edit`. If that `edit` only repeats the status and the complete applied reference, device, plan, and current configuration match, with no pending service jobs, it returns `already_applied` without creating another job or writing to RouterOS again. Additional changes, incomplete references, and pending failures still proceed.
- Ordinary updates and a general rebuild retain reconnection on effective changes. Forced manual reconnection does not bypass ownership checks, validation, or termination barriers.

This behavior was changed after confirming that the earlier incident concerned log visibility, to match the operator's requested workflow. Suspension/reactivation already closed sessions when the profile effectively changed; explicit events now also force that intent when the profile matches.

## Provisioning diagnostics in 2.4.14

- Fixed a reproduced omission: rejection before writes because an external profile could not be verified remained in the queue but did not appear in the log, even with debugging enabled.
- Profile diagnostics distinguish absence, read failure, ambiguity, and invalid identity. They include the requested name and profile path; PPP and Hotspot use separate catalogs. Valid profiles remain accepted, and a rejection preserves the account, session, and pending job.
- Each persisted failed attempt produces a safe queue record with identifiers, operation, attempt count, next retry, and error code. It excludes credentials and arbitrary exception text. Querying pending jobs or replaying a completed UUID does not produce another failure event.
- Manual synchronization produces a summary of its scope, reconnection intent, and requested, completed, and pending/failed counts. These records remain enabled when debugging is off; a logging failure does not undo provisioning results.
- Operator-provided logs after installing 2.4.13 confirmed one job for suspension and one for reactivation, each followed by an `edit` returning `already_applied`. Subsequent profile failures on other services were independent: determining their router-side cause required comparing the profile name, account type, and read result. Diagnostics alone were not treated as resolution.

## Access, transport, and dependencies

### Cancellation and connection history in 2.4.16

The limited cancellation policy in this version was replaced in 2.4.17 by local cancellation of every pending job. Running jobs remain protected.

- A new webhook change could wait behind an older prepared job even after the router recovered, because immediate execution included only the newly created Q keys. It now uses the same ordered recovery as Execute, limited to predecessors of the same service and excluding later versions. This also applies to migrations and pending UUID redeliveries. Termination barriers, worker exclusion, and service locks remain intact; the scheduled worker retains its backoff.
- The queue confused commands copied from an applied account with a removal that had already started. Version 2.4.16 allowed cancellation of a native pending removal with zero attempts, no intermediate cleanup, and no frozen execution preparation. It preserved applied state, reservations, and termination barriers, and also canceled pending replacements. Attempted prepared jobs and imports of uncertain origin remained protected in that version.
- Delete results include selected jobs canceled as a consequence of canceling their predecessor. The action creates no IDs, does not turn updates into removals, and does not contact the router.
- Evidence from router 6 showed two failed connection attempts and four waiting removals. The operator confirmed that the router remained in use and its port was accessible. Port accessibility does not establish API authentication or TLS success. New attempts replace the generic connection error with fixed, safe reasons; historical errors remain unchanged until retry.

### Recovery and presentation in 2.4.15

- Evidence for service 662 showed an older prepared job with three attempts and a missing `disabled` profile, followed by another job for the same service with no attempts. The operator confirmed having deleted the router's profiles to test reconstruction. The archived plan was not the cause of that rejection.
- Automatic creation of the configured PPP/Hotspot suspension profile is restored, using the configured suspended address list and rate limit; PPP also resolves the local address. Creation is prepared durably and verified before assigning the account and disconnecting it. Existing profiles are preserved. Failed reads, reserved names, and ambiguous identities do not permit creating or overwriting profiles.
- RouterOS adds the connected IP to the profile's address list. No placeholder entries are created to simulate an empty list. Firewall rules using that list still determine blocking; a profile and list alone are not a deny rule. A new profile requires an address list or a positive rate limit, preventing creation without a suspension policy.
- Executing a selection includes its pending predecessors for the same service once per request, without running later versions or unrelated services. Termination, migration, and concurrency barriers remain intact.
- Jobs shows which Q key blocks another job, which jobs have protected deletion, and why. The API returns concrete results and counts even on partial failure; the panel displays them and refreshes the rows. `job_action_result` logs the action and counts without credentials, including when debugging is disabled.
- Jobs adds Refresh and a configurable automatic refresh interval, stored as a browser-local preference. Queries preserve the selection of Q keys still present and neither execute nor create tasks. Automatic refresh runs only in the visible view and coordinates with actions to avoid overlapping requests.
- After a screenshot appeared to show new removals following Delete, the 2.4.14 ZIP was also inspected: that button only canceled jobs and did not enqueue removals. A regression checks that it creates no IDs and changes no operations. The screenshot did not establish the origin of those Q keys; the new action log enables correlation.
- Visible profile comments use `Plan #94` or `Servicio #1353`, without branding. Internal identity remains stable. Reads accept older comments and also verify the reserved name before accepting a new label. Migrations preserve `.id`, do not adopt manual profiles solely because of a generic label, and reject duplicate owners across comment formats.
- Plans should be renamed in UISP. If a RouterOS profile is renamed manually and its stable identifier removed, a generic label such as `Plan #94` is insufficient to identify it. The existing profile is preserved and the reserved name is created, avoiding adoption of a manual profile because its comment matches.

### Webhook outcomes and compatibility in 2.4.12

- Responses include `provisioning`, service, resolved router, requested/applied status, and known job keys and counts. They distinguish confirmed application, pending work, repeated events, documentary services, deferred status, credential requests, and removal. An empty pending table or HTTP 200 alone does not prove that a router account changed.
- `webhook_result` and `webhook_processing_failed` logs work with debugging disabled. They contain identifiers and safe codes, excluding usernames, passwords, URLs/tokens, and event bodies. Internal debug severity is separated from INFO even on PHP for Windows, where their constants can coincide.
- An unknown or ambiguous selected Site no longer silently becomes an empty Site. It is rejected before modifying the cache or cleaning up the applied account. Empty Site remains supported for documentation and the intended explicit removal flow.
- A stale historical Site name is recovered only from a verifiable applied identity for the same service; current fields take precedence. If the previous router is missing, cleanup remains pending rather than inventing another destination.
- Missing identity with automatic generation disabled returns `service_credentials_missing` instead of reporting success with no jobs.
- **Allow webhooks without a secret (compatibility)** is an opt-in checkbox, disabled by default. Enabling it accepts valid events without authenticating their sender; POST/JSON requirements, event validation, and panel permissions remain enforced. An incorrect key or plugin upgrade does not enable it.
- Configuration clarifies that the Secret field takes only the key, while the webhook endpoint takes the full URL. Pasting a URL into the secret field returns `webhook_secret_contains_url`.

Later production evidence identified missing tokens and a URL pasted as the secret. After correction, deliveries returned HTTP 200 and the plugin logged completed suspension/reactivation. On October 11, 2026, the operator confirmed that the apparent lack of application was a log-visibility issue; the RouterOS screenshot showed API access and PPP profile/account changes. Version 2.4.12 was retained for that incident without another provisioning patch. Locally reproduced routing-association failures were not its cause. That screenshot did not validate traffic measurements or other integration scenarios.

### Access controls

- The panel requires an administrative UISP session; customer-zone users cannot access it.
- Access uses `system/plugins` read/edit permissions. Mutations and backup downloads require edit permission.
- By default, webhooks require a random secret of at least 32 characters via `X-Webhook-Token`, Bearer, or `?webhookToken=...`, with secure comparison and UUID/schema validation. Compatibility mode explicitly disables only the secret check.
- Private IP addresses no longer authorize operations on their own. A webhook secret does not authorize administrative actions.
- Session-authenticated administrative writes validate the origin against `ucrmPublicUrl`.
- Authenticated responses use `no-store`; public errors omit internal details; credentials are redacted from logs.
- CRM, NMS, and authentication requests have a 5-second connection timeout and a 15-second request timeout, without following redirects. The SDK's TLS policy is retained.
- Guzzle 7.15.5 and PSR-7 2.13.1 are pinned in `composer.lock`. The SDK remains at 0.8.2.
- The 2.4.7 dependency check found no advisories applicable to the installed versions. This does not guarantee the absence of future vulnerabilities.
- Packages are reproducible with distinct version-specific timestamps. Selective PHP OPcache invalidation avoids mixing old code with a migrated database.
- The ZIP excludes operational data, keys generated by UISP, tools, and tests.
- The old EdgeRouter test containing external credentials and a destination was removed. New regressions use fictional data and local sockets; SDK development fixtures are also excluded from the ZIP.

## Installation and validation readiness

The current version is 2.4.22. Its validation status appears at the beginning of this changelog. Archived-plan recovery confirmation belongs to 2.4.19 and retains its historical scope. When duplicate IPs are reported, inspect all six views and review their owners. **Review cleanup** previews a selection of obsolete reservations and allows explicit confirmation of eligible entries with a private backup. Pending work, unresolved recovery, and an account's applied state block removal of its reservations. The generated backup remains downloadable from **System**, including after a reload. Resolve any remaining conflicts and reload to complete migration; other writes and provisioning stay paused until then. The [real-environment test guide](PRUEBAS_REALES.md) contains 81 scenarios for the remaining checks.

### Historical 2.4.17 result

**2.4.17 validation passed:** 27 isolated suites, syntax checks for 39 first-party PHP files, source/dependency correspondence, CRC checks, and exclusion of operational data and fixtures. The installer contains 351 entries, 118 first-party files, and 13 dependencies. Report: `dist/verification-2.4.17.json`. SHA-256: `af81d06f968012d7928014c4f1dc3d03f3f6ba9af7b382d1a04ff10be883fcc3`. New cases cover cancellation of prepared, attempted, and imported jobs; cleanup across multiple resources and destinations; failure/restart; partial suspension-profile creation; canceled UUIDs; and exclusion of running jobs. Canceling a native job that never started does not invent cleanup of its applied account, including with compacted history. Real UISP/RouterOS validation of that candidate remained pending at this checkpoint.

### Historical 2.4.16 result

The 2.4.16 candidate passed **26 isolated suites** and syntax checks for **39 first-party PHP files**. Its ZIP verification covered 351 entries, 118 first-party files, 13 pinned dependencies, CRC, and exclusion of operational data and fixtures. Composer found no applicable advisories in its October 11, 2026 query for 2.4.9; 2.4.16 retained those dependencies. An earlier Chrome review of 2.4.8 checked all three modes, six languages, persistence, and drafts, with no captured console errors. The 2.4.10 partial-result notices and numeric diagnostics were verified through panel regressions covering HTTP 502, opening without changes, explicit correction, drafts, and translations; no new visual browser review was performed. These checks describe earlier packages; they do not replace the current candidate's report or remaining integration tests.

The verification retains regressions for reconnection on effective changes, renames, shared-profile repair, partial failures, and queue restart. Version 2.4.12 adds suspension/reactivation with real normalization, cache, and queue components and simulated RouterOS transport, covering duplicates, pending work, removals, documentary services, invalid Site, and missing credentials. It checks INFO logs without debugging and both authentication modes through local HTTP, without accessing real systems.

Version 2.4.13 adds the real ApiAction/ApiUpdate, queue, Batch, MtData, and MT chain with an in-memory RouterOS transport: one write/disconnection per transition, omission of the accompanying `edit`, UUID replay, explicit reconnection without a change, pending-job recovery, a missing suspension profile, two manual synchronizations excluding another router, and a subsequent general synchronization. Plan/configuration changes and shared-profile revisions prevent skipping necessary updates. Panel and locale regressions check the new UI text.

Version 2.4.14 tests all four PPP/Hotspot profile rejection causes; preservation of accounts and sessions on rejection; names containing spaces/control characters; partial synchronization summaries; and failed-attempt records using the real logger with debugging disabled. It also checks that logger failures do not undo results or retries, and that querying pending jobs does not repeat events. Reproducible historical report: `dist/verification-2.4.16.json`.

Version 2.4.15 adds recovery of a missing suspension profile with an in-memory PPP/Hotspot catalog, creation before account update/reconnection, preservation of customized profiles, and rejection of failed reads or partial creation. The verification obligation and local address survive retry; mere existence does not make an incomplete profile acceptable. The real administrative API chain checks protected partial deletion, ordered execution of an older job and its successor, suspension/reactivation, and ordinary updates without extra disconnections. The simulator uses IDs unique across connections. Naming tests cover comment migration, cross-format collisions, and manual labels; Jobs tests cover HTTP 502, concrete errors, refresh, selection, and timers.

Version 2.4.16 tests cancellation of unattempted native removals, dependent-job counts in both selection orders, preservation of applied state/reservations across restart, and that version's protection of attempted or imported operations. That final restriction no longer applies in 2.4.17. Local sockets cover transport failures, login rejection, timeout/closure/protocol errors, the legacy challenge, and recovery to a valid login, with no provisioning writes after authentication failure. Immediate webhook recovery covers a failed predecessor followed by a new change, persistent failure, scope, and deduplication. The operator confirmed that a change blocked by Q16 applied after manual execution; automatic recovery still required checking after installation at that checkpoint.

Historical SHA-256 for 2.4.16: `e02d1697e13d1fd5a708cf5849b781ae2229c4bae234087bf720fe54b0f8850b`.

1. Retain the previous installer and private copies of `data/data.db` and `data/jobs.db`. Panel backups contain `data.db`; **they do not include the `jobs.db` queue**.
2. Pause workers and existing deliveries while replacing the package. Check the migration before resuming them.
3. Check webhook mode. By default, store only the key in `webhookSecret` and send it from UISP as well; the plugin does not modify the endpoint. To operate without a key, explicitly enable compatibility mode as described in the [guide](WEBHOOK_SETUP_EN.md).
4. If web PHP retains the old entry point with `opcache.validate_timestamps=0`, invalidate its cache or restart that service. The CLI and browser reloads do not refresh web OPcache.
5. In a lab, check creation, plan changes, suspension, payment, router/IP changes, partial failures, offline routers, repeated deliveries, certificates, worker restart, and restoration.
6. Verify IPv4/IPv6, firewall rules, FastTrack, and dynamic queue placement after reconnection. Restoring SQLite does not undo changes already applied to the router.

Local regressions use PHP 8.5.11, temporary databases, mocks, and local sockets. Composer's resolver retains the declared PHP 7.4 compatibility, but the candidate still requires execution on the PHP version included in the target UISP installation.

## Known outstanding work

- General read-after-write confirmation of account/profile properties and session removal. Current checks inspect API responses and prior state, with specific subsequent reads for profile renames and suspension-profile creation. General post-write verification was investigated but remained pending in 2.4.17.
- Review removal of an individual suspension queue with unexpected dependencies: the current check may preserve it while treating the command as completed. This was reproduced with simulated transport and was not observed as the cause of the reported logging incident.
- Periodic reconciliation between UISP, applied state, and observed RouterOS state.
- Ordering distinct events delivered out of order using a reliable source revision; deduplicating the same UUID does not solve this case.
- NMS topology writes remain disabled because earlier code could delete other services' nodes. Reads remain supported. Re-enabling writes requires a persistent `serviceId → deviceId` relationship and verified ownership.
- Real-environment testing across the installation's UISP/RouterOS versions, certificates, and network policies.
- Native-speaker review of the new translations.

The [draft PR description](PR_DESCRIPTION.md) presents the change for upstream review. This changelog documents implemented behavior and its limits; it does not claim that the PR is published or that all real-environment integration has been validated.
