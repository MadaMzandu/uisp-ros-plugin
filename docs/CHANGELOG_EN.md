# UISP ROS Plugin — cumulative changelog

[Español](MEJORAS.md) · [PR description](PR_DESCRIPTION.md) · [Webhook setup and rationale](WEBHOOK_SETUP_EN.md)

This revision combines [dhanielsnieto's changes in PR #89](https://github.com/MadaMzandu/uisp-ros-plugin/pull/89) with the subsequent fixes prepared in this repository. The comparison starts at `7ca8dc8`; the original PR ends at `056b89f`. The current candidate is **2.4.39**. The operator confirmed archived-plan speed recovery in their 2.4.19 installation. That confirmation covers this historical scenario; the remaining UISP and RouterOS integration scenarios still require their own checks before production readiness can be established.

The plugin connects UISP CRM to MikroTik through the **native RouterOS API**, normally on port `8728`, or `8729` with TLS. The panel manages that integration; it does not switch the transport to SSH, WinBox, or REST.

## Candidate 2.4.39: immediate synchronization and optional review

**Synchronize now** is the primary router action. It reads the current UISP catalog, discovers new services for the Site and applies each service immediately with progress, without a mandatory difference preview or waiting for cron. It reapplies managed accounts and forces their PPP/Hotspot reconnections even when settings already match. The separate secondary **Review differences** action remains read-only until the operator elects to queue proven differences or reviewed reconnections; these preview actions retain their subsequent worker execution.

Direct synchronization revalidates the exact service, Site and current client before applying and preserves the previous identity for the normal migration path. Requests process one catalog page or service at a time and retain per-service outcomes and maintenance/restore protection. A single failure does not prevent subsequent services from being attempted. Application failures keep normal queue retries; unavailable evidence cannot authorize a write. An uncertain response is not replayed automatically to avoid duplicate reconnections. Closing or reloading the page stops subsequent traversal requests; previously submitted jobs retain their own state.

Preview no longer rejects a live client before its UISP read merely because an upgraded cache has `isArchived = NULL`. Only the exact current client with an explicit non-archived state establishes eligibility. Unknown, stale or missing cache rows cannot veto that read. A currently archived client, incorrect identity or failed API remains protected, including revalidation between preview and application.

A move retains the existing address policy: an address outside the destination pool but proven to belong to another configured Site is cleared and replaced from the destination pool. A valid destination address can remain; a manual address outside every configured pool is not discarded by this rule. Replacement preparation is validated before source removal, then migration performs source deletion followed by destination application. A destination failure after source removal remains pending for retry; uninterrupted migration is not guaranteed. Manual/foreign accounts and current owners' reservations remain protected.

**Settings** uses a distinct toothed gear with a circular center, including the panel’s local SVG fallback.

Validation for 2.4.39 passed: **64 isolated suites**, syntax checks for **67 first-party PHP files**, 381 archive entries, 148 first-party files and 13 locked dependencies. Report: `dist/verification-2.4.39.json`. SHA-256: `dc7bcf7cb12ff783ab114c331522069968917ad20bbf6b4295a3ec8030d0dc36`. Synthetic browser checks cover separate actions, progress, the mobile dialog, Spanish/English, light/dark themes and the settings gear. No production UISP or RouterOS changes were executed.

## Candidate 2.4.38: retire obsolete service intent and organize System

The queue reads current UISP state before replaying old provisioning intent. Freshly confirmed ended, obsolete or inactive services (`2`, `5`, `8`), archived clients, or service absence established by a verified HTTP 404 can retire their previous pending updates. **Suspended status `3` preserves its account and assignment** and is not treated as termination. A broken service-page link, stale cache or PPP username suffix alone proves none of these states.

Reservations can be released locally when no possible physical resources remain. Applied state, prepared commands, uncertain older attempts and retained resources instead produce normal guarded removal jobs; addresses remain protected until cleanup is confirmed. Lifecycle and resource ownership are checked again before writing, preserving an account now owned by another service. Reactivation, contradictory identity or an unavailable API leaves a service-specific incident rather than authorizing deletion or overwrite of a current owner. Fair turns and bounded budgets do not guarantee clearing hundreds of services in one pass. Removing Site or the account identifier is verified as a separate configuration detachment; it is not confused with a reactivated billing termination and does not replay an obsolete update before cleanup.

The scheduled review includes historical owners, and an actual allocator conflict can review its recorded owner before retrying allocation. Retaining a cancelled intent no longer makes its reservation permanent. For requester `7279` and reservation holder `9807`, fresh proof that `7279` ended or its client was archived retires that obsolete request while preserving the current `9807` assignment.

**Review requesting service** opens explicit fresh evidence with links to the known service and client. **Retire service pending work** requires an eligible preview and a single-use token, then revalidates before changing the queue. Results distinguish released reservations from **Removal scheduled** and link to the removal jobs. An uncertain response consumes its permission and requires a new review. Finite error messages explain unavailable UISP, identity checks or unfinished router cleanup; unknown evidence cannot enable retirement. A paused migration retains its separate compatibility barrier.

A new panel visit opens **Devices**. A real reload preserves the selected section and scroll position; a remembered Jobs section no longer changes the landing page. **System → Plugin backups** groups the full ZIP and previous/cleanup SQLite copies with explicit scope. ZIP preserves queue and operational state; legacy SQLite retains the primary-database scope and cleanup copies remain download-only. Backup actions no longer repeat in Utilities. New System sections are more compact; the general redesign and notification center remain outstanding.

Automated/package validation: **63 isolated suites passed**, syntax checks for **66 first-party PHP files**, and verification of 380 archive entries, 147 first-party files and 13 locked dependencies. Report: `dist/verification-2.4.38.json`. SHA-256: `ebbb4de8aa1d0e6a14e0b8f89981639cbfab3e15956397c1edb72e6f90bcda73`. Synthetic browser checks cover desktop/mobile, Spanish/English and light/dark themes, including the dialog at 390 × 844. No production UISP or RouterOS state was changed; lifecycle cleanup and address reuse still require verification in the affected installation.

## Candidate 2.4.37: reviewed synchronization, fresh state, reconciliation and full backup

**Review synchronization** prepares a router preview containing unchanged services, differences and cases requiring review. Bounded UISP and RouterOS reads do not refresh cache, reserve addresses, change CRM/router state or run jobs. It shows IPv4/IPv6, checked fields, reconnections and, for a move, the previous and destination routers. **Review only** closes without applying. **Apply differences** queues proven changes; **Force reconnection** also includes verified unchanged accounts. Application rechecks identity and current state, then queues one service at a time through the normal worker for a subsequent scheduled pass. Cancelling stops subsequent requests and preserves the outcome of an already submitted request. Unknown evidence, manual/foreign accounts and concurrent changes do not authorize overwrites.

**Automatic difference review** runs after jobs: at most one service per minute, with a persistent fair cursor and an eight-second budget. It compares fresh UISP, applied state and the observed account. Automatic corrections require a proven owned account on the same router, complete evidence, no active operation and unchanged versions/configuration. Ambiguous cases remain service incidents without stopping unrelated work. System can disable the review or its automatic application. The initial scope does not adopt manual accounts, perform arbitrary migrations or release addresses merely because a client is archived or a service page is absent.

New webhooks fetch the service's current UISP representation under its service lock before updating local data or scheduling work. Older events cannot restore their frozen attributes or status. If an explicit lifecycle action contradicts the observed state, or evidence is unavailable, a bounded durable inbox stores only the service identity for a fresh scheduled review. That review fetches current state instead of replaying the old action/body. UUID deduplication remains. This avoids stale snapshots without inventing a chronological source revision or assuming immediate UISP API consistency.

**System → Full backups** creates, downloads or uploads a private ZIP containing the supported databases, queue, cache and operational state, including credentials. Existing `data.db` SQLite backups remain separate. Full restoration requires a count preview, explicit confirmation and a token bound to current state. The token is consumed before mutation; restoration creates a complete pre-restore backup and journals replacements so startup can roll back an interrupted swap. The current host's SDK integration is preserved rather than imported from the archive. Restoration does not undo CRM or RouterOS commands: restored pending jobs may run on the next scheduler pass. An uncertain response requires reload and a new review; neither the token nor stale forms may replay writes. The result offers the pre-restore backup for download.

**Devices → Pool capacity** reads IPv4/IPv6 capacity on demand using exact decimal-string counts: usable total, assigned, retained, excluded and free. Overlapping intervals are unioned, and protected historical addresses remain occupied. Shared pools, low capacity and exhaustion are flagged; unavailable or incomplete sources remain unknown. IPv6 counts use the displayed client prefix. This read never allocates or reserves addresses and does not guarantee capacity until normal job preparation.

Job errors link the requesting and blocking services, plus their stored clients when a unique association exists. A PPP username suffix never establishes a client relationship. A blocking service returning verified HTTP 404 can be reviewed through the explicit cleanup flow, retaining its guards, maintenance exclusion, backup and confirmation. Archiving a client or deleting a job alone does not release valid reservations, applied ownership or protected resources.

IP conflict errors distinguish the requesting job from the service holding the reservation and link to both records. An old requester does not authorize releasing a current service assignment.

The broader compact redesign and notification center remain deferred until the final stage. **2.4.37 validation passed:** 62 isolated suites, syntax checks for 65 first-party PHP files, and installer verification of 379 entries, 146 first-party files and 13 locked dependencies. Report: `dist/verification-2.4.37.json`. SHA-256: `1284c74f580d8e792479694ebb0df97f1f055b033577c72999e62e7ba386adc7`. Browser checks covered desktop/mobile, Spanish/English and light/dark themes with synthetic fixtures; no production UISP or RouterOS changes were performed.

## Candidate 2.4.36: RouterOS confirmation, service diagnostics and automation health

New jobs read back effective account, profile, queue and disconnection properties after executing their commands. Jobs distinguishes **Applied and verified**, **Applied, awaiting confirmation** and **Router differences detected**, with timestamps and field evidence. Failed observation never retries a completed write or turns it into pending work. **Verify again** only observes; it does not replay provisioning or reconnection. Cross-router moves preserve old-router cleanup evidence across failed replacement attempts. Receipts are bound to job/version and router endpoints; older versions are identified and pre-upgrade jobs are not given invented confirmation.

**Service diagnostics** searches local service, client, username and IP records. Selecting a service reads fresh UISP and exact filtered RouterOS state and compares them with queued/applied/cached expectations: username, Site, IPv4/IPv6, profile, plan, status and ownership. It shows each source's timestamp, differences, job history, attempts, next retry and informational retained history. Missing evidence remains unknown. Diagnosis never provisions, reconnects, updates cache or edits CRM.

**System → Automation health** shows observed scheduled starts/completions, the last authorized webhook, pending/running/failed counts, oldest pending work, stored next retry, cache refresh and maintenance hints. Manual runs do not create scheduler heartbeats. Activity recording has a short bounded wait; file contention may skip an observation without stopping provisioning. Webhook acceptance does not mean provisioning; the external cron schedule remains explicitly unknown. Health reads local storage only, pauses polling during mutations/hidden tabs, and retains no credentials or response bodies. Authenticated same-origin operations have strict JSON/action validation and work without triggering setup or migrations.

Observation uses bounded read-only RouterOS queries, current credentials and endpoint/version guards. Confirmation establishes the observed configuration and removal of identified old sessions; it does not establish subscriber Internet reachability. The queue remains first, compact recoveries follow it, and recent confirmation cards follow recovery. All new controls support the panel's six languages. Reload after upgrading; subsequent scheduled deliveries and new jobs populate health and receipts.

**2.4.36 validation passed:** 54 isolated suites, syntax checks for 58 first-party PHP files, and installer verification of 371 entries, 138 first-party files and 13 locked dependencies. Current report: `dist/verification-2.4.36.json`. SHA-256: `e56d016e13804415acfca3400588c729fa41dc895332f105e47998f25fe47508`. Browser checks cover desktop/mobile, Spanish/English and light/dark themes with synthetic fixtures; no production UISP or RouterOS changes were performed. The report retains the initial contention-test failure and its subsequent check against the identical ZIP.

## Candidate 2.4.35: verify and repair a contradictory RouterOS service marker

PPP account verification no longer requires an optional Hotspot attribute missing from a valid UISP catalog. Site and username mappings remain mandatory; Hotspot accounts still require their verified attribute. Diagnostics identify which mapping could not be verified and describe the service ID as indicated by the router comment, without assuming it identifies a previous owner.

A comment combining the verified current client with another client's service ID can be corrected in place only when the exact router, job and fresh UISP username, destination Site and current IPv4 agree. The indicated service must have a different verified client, username, router endpoint and IP. Both services and the exact router record are reread; applied account claims, executable jobs and cleanups remain checked, and a private password-free backup precedes the update. Unrelated work for the other service does not block solely because of its ID when its destination and identity prove it cannot touch this account. Unknown or conflicting evidence retains protection.

Prepared PPP/Hotspot retries also normalize the outgoing comment client/service IDs from their own snapshot while preserving its readable label, account identity, addresses and credentials. Independent router ownership checks still run; deletion evidence stays unchanged. This prevents restoring an inconsistent frozen marker without claiming it caused the observed production data.

Verification only reads UISP. Correction updates the exact identified router account, without editing or removing the service mistakenly named by its comment. Existing terminal/verified-404 same-client reassociation remains available. After upgrading, reload the panel and retry the affected job; clearing the queue does not repair a router comment. Production results remain to be confirmed after the retry.

**2.4.35 validation passed:** 47 isolated suites, syntax checks for 55 first-party PHP files, and installer verification of 367 entries, 134 first-party files and 13 locked dependencies. Current report: `dist/verification-2.4.35.json`. SHA-256: `92a773f1021233b4407d5a42466d8ee799322cad3db35dd7b703e208581389e1`. Tests use synthetic fixtures and simulated/local transports; the production retry remains to be confirmed.

## Candidate 2.4.34: correct queued foreign-Site IPs and obsolete account links

Already-prepared jobs recheck a foreign-Site address against fresh UISP state before replaying router commands. They clear only that IP attribute and prepare a destination-pool address when the old value remains; a newer manual UISP address takes precedence. Allocation remains stable across retries, and previous addresses stay protected until the full update succeeds. Rebuilding preserves resource identity, the service plan and credentials.

A PPP/Hotspot account marked with another service of the same client can be updated in place after fresh checks confirm the current service, username and Site, and a terminal or explicitly HTTP-404 previous service. Other owners and executable jobs are checked; a private password-free backup precedes permission, and the exact router record is reread before writing. An active previous service, an unmarked/manual account, another client or failed evidence retains protection. Jobs explain the specific reason and link both service IDs.

After installation, the next scheduled retry or **Run** in Jobs uses this correction. Configured plan speeds are preserved. Tests use synthetic fixtures; each production account still needs its UISP/RouterOS result checked.

**2.4.34 validation passed:** 47 isolated suites, syntax checks for 55 first-party PHP files, and installer verification of 367 entries, 134 first-party files and 13 locked dependencies. Current report: `dist/verification-2.4.34.json`. SHA-256: `851d250ef1c9124e4bc86564f72fe5099dada37ec16fc682f1536a8eb2141be1`.

## Candidate 2.4.33: fresh pool addresses after an IP clear or Site move

An explicitly cleared UISP IP now replaces cached desired state and requests another address from the effective destination pool. A durable allocation generation keeps that choice stable across retries and paired events. IPv4 and IPv6 replacement preparation succeeds together before router changes; later synchronization also recognizes a missed clear webhook from the last confirmed fixed desired value.

When an explicit address is outside the destination pool and belongs to another configured Site pool, the plugin freshly validates the service, client, Site, attribute and current IP. It checks replacement capacity before clearing only that IP attribute and confirms the clear by reading UISP again. A manual address outside all configured pools is unaffected by this rule. Other services' reservations remain protected. Exhausted capacity or changed evidence fails only the affected service and preserves its previous router account.

Scheduled IP export respects a different current UISP value. Publishing into an empty field requires a current applied pool-allocation proof and a one-time generation receipt, consumed before PATCH so an uncertain response cannot replay an old address. Unconfirmed CRM-edit holds remain active. Valid address conflicts now identify the blocking service and source separately from malformed-address errors.

A new synchronization can replace an obsolete pending intent with no new prepared commands when its earlier cleanup is already confirmed, without repeating that cleanup. Incomplete cleanups and prepared commands retain ordering. After upgrading, synchronize the service or router again to create current intent; retrying an old job preserves its earlier snapshot.

**2.4.33 validation passed:** 45 isolated suites, syntax checks for 54 first-party PHP files, and installer verification of 366 entries, 133 first-party files and 13 locked dependencies. Current report: `dist/verification-2.4.33.json`. SHA-256: `6aa920f75a8b03745711e9d2f07d294f03bbd4ff218fe022d4c6f177e4163384`. Tests use synthetic fixtures, temporary databases and simulated/local transports; no production UISP or RouterOS changes were performed.

## Candidate 2.4.32: automatic checks and explicit recovery outcomes

The scheduler checks malformed local reservations and recoveries before running jobs, outside worker locks. Each pass reviews at most one service/family and one recovery within a 12-second budget, using persistent fair cursors. It applies only proven local repairs against fresh UISP evidence and redundant/fully observed-absent recovery resolution, with backups and revalidation. It never automatically preserves or force-discards uncertain history, PATCHes CRM, or disconnects accounts from this review. Failed checks do not abort other jobs.

A malformed peer reservation is informational and cannot stop another service’s allocation. Its row remains visible for repair; valid cached, applied and historical resource addresses remain exclusive. An invalid address belonging to the current service fails only that service.

A compatible recovery no longer prevents repairing a malformed local field. Its records and valid addresses remain unchanged; differing valid ownership, conflicting identities and incompatible executable work remain protected. Replacement address preparation is checked before update cleanups so an invalid replacement address cannot cause cleanup first and validation failure afterward.

**Keep resources and close review** freshly validates the same service/client in UISP and backs up the queue before moving exact historical records into protected storage. Valid addresses, device references and shared-resource membership remain held through restarts and source-history compaction. These records are excluded from executable cancelled cleanups on later updates and remain visible separately. This closes a local review; it does not prove that the router is empty or synchronized and does not release historical IPs.

**Discard local record** explicitly removes only the selected cancelled or preserved history, including when UISP is unavailable. Local preview, service/job confirmation, a single-use token, concurrency checks and a private coherent backup precede deletion. Current jobs, applied ownership, current reservations, CRM and routers remain unchanged. The review warns that historical resources may remain on the router and their IPs may become reusable when this protection is removed. It is never automatic and does not clear current ownership conflicts.

The executable queue comes first; compact recovery cards follow directly below in one, two or three columns depending on available space. Confirmed outcomes refresh the list and indicator. Specific incomplete-history diagnostics expose safe metadata only.

**2.4.32 validation passed:** 42 isolated suites, syntax checks for 53 first-party PHP files, and installer verification of 365 entries, 132 first-party files and 13 locked dependencies. Current report: `dist/verification-2.4.32.json`. SHA-256: `2d679a68b6d77f3f11ca3d9006ebaa056f102f2b31c1e408b9733875e37fee0c`. Browser checks use synthetic fixtures; no production UISP or RouterOS changes were performed.

## Candidate 2.4.31: dismiss warnings and repair reservations retained by cancelled jobs

All three top warnings can be dismissed and restored with **Show warnings**. Dismissal lasts within the tab using only coarse counts/state, without storing client identities, IPs or credentials. Poll timestamps and navigation do not reopen it; relevant count/error changes or clearing and recurrence do. Sidebar badges, detail views and paused-migration write protections remain active.

Malformed-reservation repair distinguishes pending work from cancelled/superseded intent. A cancelled unprepared update without recovery debt no longer blocks solely because its requested IP is obsolete or malformed. Its bytes and valid reservations stay intact, while identity, prior ownership, applied resources and the selected address family remain guarded. A different active pending intent is still protected and exposes its job ID/state with navigation to review it. Repair never revives, rewrites or runs cancelled jobs. A real-queue regression verifies that an unrelated service resumes after local corrections while cancelled operations remain unattempted.

**Compare with UISP and router** retains exact redundant-copy consolidation and adds read-only observation when recorded applied ownership is insufficient. Router queries use only exact filtered `print` requests with limited properties, following the [RouterOS API documentation](https://help.mikrotik.com/docs/spaces/ROS/pages/47579160/API). They never provision, adopt, remove or disconnect resources. The recorded endpoint must still match the configured device; current credentials are used. Review shows device/path/selector and present/absent/unknown evidence. Absence resolution requires every retained resource and prior reservation to be represented and observed absent, repeats observation under the concurrency guards, backs up `jobs.db` and marks only that cancelled source job cleaned. Applied ownership, CRM and routers remain unchanged. Present/shared resources, changed authority, incomplete history and failed reads remain protected; partial observation never establishes absence. Deleted operations do not run in a hidden queue.

Old generated previews, helper scripts, screenshots and reports are removed from the working folder. The repository's historical installer is replaced with the verified current one. Reproducible tools, tests, source, documentation and runtime dependencies remain; `dist` contains only current delivery/verification files and a separate screenshot directory.

**2.4.31 validation passed:** 40 isolated suites, syntax checks for 52 first-party PHP files, and installer verification of 364 entries, 131 first-party files and 13 locked dependencies. Current report: `dist/verification-2.4.31.json`. SHA-256: `48da9d4bd968068f1e85323ae63c39409f98e85ce1b7bad78fefb34306824cab`. Browser checks use synthetic fixtures; no production CRM or RouterOS changes were performed.

## Candidate 2.4.30: review retained recoveries and explain rejected UISP edits

The indicator distinguishes executable jobs from retained recovery records. An empty executable queue now shows **Pending recoveries**, while mixed state shows **Jobs and recoveries**. **Review** opens **Services in recovery** inside Jobs, with service, known client, source job and device identifiers. Listing reads existing local storage without initializing/importing the queue or querying CRM/router; failed reads remain explicitly unavailable.

**Review recovery** freshly checks the UISP identity/status and compares retained snapshots with recorded applied state. Resolution is offered only for exact redundant copies, with a private ten-minute review, explicit confirmation, fresh evidence, concurrency barriers and a coherent private `jobs.db` backup. It issues no router commands and preserves services, addresses, plans, cache, applied ownership and job rows. Confirmed results refresh both inventory and counts. Different resources, distinct previous reservations, unknown identities, active work and incomplete evidence remain protected with an explanation. This local consolidation does not replace physical reconciliation of old resources; do not run a bulk synchronization merely to hide a count. The recovery backup is downloadable from the result or System and is separate from restorable `data.db` backups.

Malformed-reservation repair now handles a failed initial provisioning that saved a reservation before saving the local service/client. Fresh exact UISP service/client reads are required; conflicting local/cached identities and durable ownership remain blockers. It never creates or fills in a service row, changing only reviewed malformed fields. Cancelled/superseded unprepared updates without recovery debt may remain unchanged when the same fresh identity/address and prior-reservation checks succeed; their rows are neither modified nor counted as pending. Blocked results expose the safe diagnostic stage and known client IDs, distinguishing missing local metadata from unavailable reads or a different owner. A malformed identity or HTTP 404 without established local ownership stays protected.

CRM IP edits distinguish accepted, explicitly rejected and unconfirmed requests. Safe HTTP status/reason diagnostics retain no private response bodies. **Verify again** remains read-only and never replays a PATCH; a later observed matching value does not turn the original rejection into a confirmed write. Ended services are never reactivated to force editing. Reviewed historical assignments retain their CRM IP and exclude only that exact ended service/client/family/attribute/value from actionable conflicts; reactivation or changed evidence revokes the exception. Historical classification alone does not establish removal of RouterOS resources.

**2.4.30 validation passed:** 39 isolated suites, syntax checks for 51 first-party PHP files, and installer verification covering 363 entries, 130 first-party files, and 13 locked dependencies. Current report: `dist/verification-2.4.30.json`. SHA-256: `a285440ef37b0abbc2d45ef5f5648cedee0859e6bb238ab29fced06f9c0abf96`. Browser checks used synthetic fixtures for empty executable queues with listed recoveries, confirmed consolidation, refreshed counts, preserved differing resources and conflicting-client diagnostics in Spanish/English and dark/light themes. No production CRM or RouterOS changes were performed.

## Candidate 2.4.29: repair malformed local reservations and show pending work

**IP conflicts → Invalid reservations** lists malformed network and legacy service fields without querying CRM or initializing the queue. The failed-job diagnostic also offers **Review reservation** for a validated blocking service ID. **Compare with UISP** checks the current service/client identity, configured IP attribute and selected family before proposing a local correction. Only malformed fields are changed; valid reservations, other families, CRM, service state, cache, jobs and routers are preserved. An explicit missing address or HTTP 404 may clear only the reviewed malformed fields when ownership permits it. An archived client or ended service alone never authorizes clearing an applied address.

Replacing a malformed value uses a complete fresh bounded CRM collection, with canonical address/range comparisons against CRM, local and cached reservations, and durable applied/recovery ownership. It supports up to three selected services, a 12-second budget for starting reads, 28 requests, and 20 pages of 500 services; a started request uses the existing transport timeout. Missing evidence, foreign malformed CRM addresses, repeated/incomplete pages or exceeded limits preserve the reservation. A known valid applied address may be restored only for the same service/client and exact confirmed address; differing, malformed or missing applied ownership remains protected. A different ended CRM owner is excluded only by its exact freshly matching historical review; reactivation or changes to its client, IP or attribute revoke that exception. Local, cached and durable ownership remain independently protected.

Apply requires an administrative edit grant, same origin, a private ten-minute preview, fresh identity/configuration/local/ownership checks and maintenance, setup, backup and attribute-export barriers. It saves a coherent private SQLite backup, then changes only the reviewed malformed sources atomically. Download the backup from the result or System. Unprepared pending updates whose identity and desired address agree with UISP can remain queued; their exact invalid previous reservation is not mistaken for confirmed router ownership. Prepared commands, active work, cancellation debt and ambiguous ownership block repair. The operation never runs jobs itself; normal retries retain their existing schedule and order. Reload the panel after a confirmed correction.

The panel now shows a persistent **Pending jobs** warning and sidebar count, with pending/error, running and recorded recovery counts and a direct link to Jobs. A separate read-only local endpoint polls every 30 seconds while the panel is visible, pauses during administrative writes and refreshes after they finish. It never initializes, imports or recovers a queue, reads CRM, or blocks other clients. Failed reads retain the previous count as stale; unknown state is never presented as zero. Imported legacy jobs and recovery entries are deduplicated.

Configured plan speeds, including speeds above the advertised plan name, remain unchanged. A local reservation correction does not rewrite immutable queued plan/status intents or fix invalid plan metadata; subsequent normal retries and a fresh synchronization retain their own validation.

**2.4.29 validation passed:** 38 isolated suites, syntax checks for 50 first-party PHP files, and installer verification covering 362 entries, 129 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.29.json`. SHA-256: `f3e8c432d11d106e0ff15cbe4a68516526d6b786ad2266511b40c42c3be74710`. Browser checks used synthetic data to verify the persistent pending-job warning, malformed-reservation comparison, explicit confirmation, backup access, and refreshed inventory. No production CRM or RouterOS changes were performed.

## Candidate 2.4.28: show the result of a CRM IP change and verify uncertain outcomes

After applying a reviewed IP correction or historical exclusion, the editor displays a dedicated result instead of returning to the original form. It retains the previous address, the requested outcome, and the current value reported by UISP. A confirmed change, a still-present previous address, a different current address, a missing service, and unavailable evidence have distinct messages. A confirmed operation receipt remains identifiable if the follow-up query fails or later reports a different state.

The panel refreshes the selected conflict and its pending count with a bounded verification, then reloads the local monitor report. It does not launch a complete CRM scan. **Verify again** performs only fresh reads: it never repeats the mutation or reuses its consumed token. If a later query matches an uncertain request, it reports that UISP now shows the requested state, without claiming that the original write was acknowledged. Unknown evidence or a changed client identity cannot manufacture success. Further edits require a fresh preview and confirmation.

An existing, freshly verified ended service can explicitly switch from an unconfirmed edit to **Resolve historical assignment**. The historical alternative has its own review and confirmation; it preserves CRM history and routers. A historical result is checked against the same client, ended status, selected family, exact address, and current reviewed marker. Missing, reactivated, reassigned, or unverified services cannot use the historical fallback. Local cleanup remains a separate reviewed action with its existing backup and ownership protections.

Panel asset URLs have a new revision so updated JavaScript and styles are requested when the new panel is loaded.

**2.4.28 validation passed:** 35 isolated suites, syntax checks for 48 first-party PHP files, and installer verification covering 360 entries, 127 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.28.json`. SHA-256: `ab16abdbc2b2e63f5a2c6418d920766e723e8c313aa80a285ec211f4ea0f4956`. Browser checks used synthetic data to verify explicit outcomes, read-only reverification, and the reviewed historical alternative for an ended service. No production CRM or RouterOS changes were performed.

## Candidate 2.4.27: distinguish resolved conflicts and cleaned reservations

A fully verified group moves immediately to the green **Resolved conflicts** section and no longer contributes to the warning or sidebar's pending count. The UI requires complete matching service IDs, client identities, known per-family evidence, and no remaining effective duplicate; unknown, truncated, reassigned, or unobserved groups stay pending. If all reviewed groups are resolved, the pending list says so while retaining the scope and time of the completed automatic scan.

The monitor reports the start time of its completed cycle independently from publication. Pages from a scan started before a newer selected verification cannot immediately resurrect the old warning. A later completed review, changed group/identity, reactivation of a reviewed ended owner, or mapping change revokes the applicable proof. Selected verification remains in memory for the open panel, outside navigation storage; a reload uses the automatic report and fresh explicit verification. It does not persist customer evidence in the browser or start a full CRM scan from polling.

Local cleanup now distinguishes **Already cleaned** after a successful cleanup receipt from **No local reservation** after fresh per-family absence checks. It checks both current network rows and legacy service fields, including values such as zero that must not be treated as empty. Unknown schema, failed reads, remaining other-family assignments, and applied ownership remain conservative. Empty CRM-source previews return a neutral status without a cleanup token or CRM requests. Cleanup success performs one bounded verification of its originating group; a continuing CRM duplicate stays pending, and failed follow-up verification preserves the confirmed cleanup receipt without claiming CRM resolution.

The new monitor metadata is upgraded only by the scheduler from its exact known v3/v4 schemas to v5. Panel reads leave previous schemas unchanged. The version retains per-device synchronization progress and existing backend protections.

**2.4.27 validation passed:** 35 isolated suites, syntax checks for 48 first-party PHP files, and installer verification covering 360 entries, 127 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.27.json`. SHA-256: `fcabbdb8b67dc77d2cbefa97e339bad4270eabb5a885199f3bc01d3f32aa8b88`. Browser checks used synthetic data to verify the green resolved section, cleared pending count, and cleaned reservation indicator. No production CRM or RouterOS changes were performed.

## Candidate 2.4.26: resolve CRM conflicts and synchronize devices without blocking the panel

Device synchronization shows progress in the selected device row. The panel remains available and can submit synchronization for other devices concurrently. A device cannot be submitted twice while its request is active, including after filtering or returning to the view. Results identify the device and preserve partial or pending provisioning outcomes. Existing service and physical-router locks remain in place; shared physical endpoints may defer work for retry. Manual synchronization still reconnects the selected device's managed PPP/Hotspot sessions.

Automatic CRM conflict owners now offer explicit verification, correction, and local cleanup actions. Verification reads current service addresses and client identity/archival, displaying its time and scope separately from the completed automatic snapshot. A selected check does not establish that the entire CRM is free of conflicts. Corrections remain available for reported malformed attributes as well as duplicated addresses.

**Edit IP in CRM** previews changing or explicitly removing only the selected IPv4/IPv6 custom attribute. Applying requires an administrative edit grant, same-origin request, private expiring preview, fresh service/catalog checks, and explicit confirmation of the service, family, and intended value. Changed evidence requires another review. The request patches only the configured IP attribute and reads back its outcome; ambiguous results remain unconfirmed and are not silently retried. Removing an IP does not delete a service. The endpoint does not directly provision routers; normal UISP edit webhooks may still trigger the existing provisioning flow.

A private correction guard prevents daily IP export from restoring an old local reservation over the deliberate CRM change. Local reservation cleanup remains a separate preview and confirmation with backup, fresh evidence, and existing pending/recovery/applied-ownership protections. The CRM entry point supports obsolete local reservations without requiring a duplicate in the local database and keeps healthy panels writable. Archived clients or ended services alone do not authorize local cleanup.

For an ended service that UISP cannot edit, **Resolve historical assignment** requires fresh status `2` and an explicit review of the selected address. It records a private decision for that exact service, client, family, attribute identity, and value; it does not PATCH CRM, reactivate a service, delete its history, or issue router commands. The full monitor retains the original address in a separate reviewed-history section and excludes only that reviewed ended family from actionable duplicate groups. The other current owners remain monitored. The exception stops applying when the current evidence differs or becomes unknown; an active service never inherits it merely because its client is archived. Selected verification shows the result immediately, while the global warning waits for a completed scan. This CRM classification does not establish that old RouterOS resources have been removed.

Historical local cleanup is separately available even when the CRM address remains unchanged: it requires the exact historical proof to still match a freshly read ended service, and retains backup, maintenance, queue, and applied-ownership checks. A pending job or recorded applied account remains a reason to preserve that reservation. Unknown mappings/attributes do not authorize either exclusion or cleanup. The monitor's exact known v3 schema is read without writes by panel polls; only the scheduled process upgrades it to v4, preserving raw addresses and per-family evidence.

**2.4.26 validation passed:** 35 isolated suites, syntax checks for 48 first-party PHP files, and installer verification covering 360 entries, 127 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.26.json`. SHA-256: `b290f01d7ca0f5a2f38ec4cc827096844f49763df92285a8b51d94f27ff90a3b`. Browser checks used synthetic fixtures for overlapping device progress, CRM correction preview/confirmation, historical resolution of an ended service, and healthy local cleanup. No production CRM or RouterOS changes were performed.

## Candidate 2.4.25: automatic CRM duplicate-IP warnings

The scheduled process observes CRM services through an independent monitor after processing jobs and outside their locks. It starts scans every five minutes, reads at most one catalog and two 500-service pages per run, and resumes larger collections on subsequent scheduled runs. Five minutes is the start interval; completion of a large catalog depends on scheduler frequency. A soft 12-second budget prevents further reads from starting; an already started request retains the existing 15-second transport timeout.

While visible, the panel polls only the local report every 30 seconds. It shows **Duplicate IPs in CRM**, completion time, reviewed families, and client/service links. This warning does not activate recovery mode or disable controls, jobs, automatic suspensions, or reactivations. The separate protection for stored duplicates blocking migration remains in place. IP conflicts remains available during normal operation and retains the navigation behavior from 2.4.24.

The monitor publishes a complete collection of valid identities with per-family evidence and canonical addresses. An invalid or incomplete address does not prevent detection of other valid duplicates: it is marked unknown, retaining only that same service's previous address as pending evidence. An explicitly removed address is cleared on completed review. Transport or invalid-page failures retain the last report as stale. Partial coverage is visible and never establishes that every IP is conflict-free. Private state contains only IDs, status, and necessary addresses; it does not edit reservations, cache, queue, or routers.

The repeated “Invalid address in a reserved network” failure is also addressed. Reapplying a manual IP exactly equal to the service's own valid reservation acquires no new ownership and does not scan unrelated reservations. Suspension/reactivation can therefore continue for that assigned service despite another malformed legacy row. New or changed assignments retain their acquisition checks. Invalid own reservations, including `"0"` or IPv6 without an explicit length, are rejected before use. Jobs and logs identify the blocking service, source, and family through `invalid_network_address`, without exposing arbitrary values or credentials.

The independent plan 99 failure (`invalid_speed` for `uploadSpeed`) still identifies CRM data requiring review. The previous catalog is retained; this change does not invent speeds or rewrite plans to hide the error.

**2.4.25 validation passed:** 34 isolated suites, syntax checks for 46 first-party PHP files, and installer verification covering 358 entries, 125 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.25.json`. SHA-256: `e12899924c05f8c739deb30004d5430cd0ee6c4113683579285fd7cd1c9f60e6`. Browser checks covered the independent warning, enabled operations, safe links, and address issues with synthetic data. Regressions verify continued processing for already assigned services and safe retention of partial evidence. Production integration retains its own verification scope.

## Candidate 2.4.24: retain navigation and compare current CRM addresses

Reloading retains the open section and list position in the same tab. IP conflicts also uses a service ID as an anchor to return to the visible owner when row heights change. Restoration covers desktop and mobile scrolling, waits for rendered data, and rejects late responses from a different view. If IP conflicts is no longer available, the panel falls back to Devices. Session storage contains navigation only; verification facts, customer names, addresses, credentials, and cleanup authorization do not survive a reload.

**Verify in UISP** now compares each family's saved address with the current service attribute, distinguishing matching, removed, changed, and unknown. An explicitly complete empty attributes list proves absence only after the configured attribute is uniquely validated against the current catalog. Omitted properties, invalid values, ambiguous mappings, and failed reads remain unknown. Reads remain explicit and bounded to three services, three clients, and one attribute catalog per request, with a soft 12-second budget for starting further reads. Verification does not write cache or reservations.

**Review cleanup** also accepts a stale local address removed or changed in a service that still exists. It requires matching identity and a current peer still using the old address, then rechecks live evidence, configured mapping, and local snapshots before confirmation. It clears only the selected family and its legacy field, preserving the service and the other family. A private backup precedes the transaction; pending work, unresolved recovery, and recorded applied RouterOS ownership remain blockers. An empty CRM IP alone does not prove the router released it. The existing exact-HTTP-404 path retains its checks.

During normal operation, an already reserved new IP is rejected while preparing its affected job; other services can continue automatic suspension/reactivation. Global consultation mode applies when the main plugin database already contains duplicates. This diagnostic does not continuously monitor every CRM attribute.

**2.4.24 validation passed:** 32 isolated suites, syntax checks for 45 first-party PHP files, and installer verification covering 357 entries, 124 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.24.json`. SHA-256: `a9c899ec39a5d395cc322394ad263a587a704b7055bd5350ba342b0a73cd818e`. Browser checks covered retained section/position after desktop and mobile reload, current IP comparison, and review of a removed address with synthetic data. Cleanup retains job and applied-ownership protections; these checks do not modify production UISP or routers.

## Candidate 2.4.23: identify client archival independently

The previous cache retained client names but omitted `isArchived`. Diagnostics could therefore show a service as “Active (saved data)” while failing to identify its archived client. The cache now retains a nullable archival flag, distinguishing archived, not archived, and unchecked. Migration adds the column while preserving existing rows; an absent flag never becomes false.

IP conflicts shows client lifecycle separately from service status, with a highlighted archived indicator. **Verify client** reads the client record directly, including when an older service no longer exists. It only accepts clients associated with current diagnostic owners, deduplicates shared clients, and checks at most three clients per request with a soft 12-second budget for starting further reads. Source, verification time, and errors remain visible. These reads do not edit service records, cache, reservations, jobs, or routers. Opening the panel still uses saved data only.

Normal cache refreshes and partial client events preserve the new field. A legacy cache remains read-only during a paused migration; Verify client explicitly checks its current state. Archival alone never makes a reservation eligible for cleanup: all 2.4.22 checks and ownership protections remain in place.

**2.4.23 validation passed:** 32 isolated suites, syntax checks for 44 first-party PHP files, and installer verification covering 356 entries, 123 first-party files, and 13 locked dependencies. Report: `dist/verification-2.4.23.json`. SHA-256: `724ec7240531954968fbe4a516e473013e9b4b184cbb114fe4f6095e508b598f`. Browser checks also covered the independent archival indicator and direct client verification with synthetic data. Migration and diagnostic reads leave services intact and retain reservation protections. Real UISP integration retains its own verification scope.

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

**2.4.19 validation passed:** 28 isolated suites, syntax checks for 40 first-party PHP files, and installer verification covering 352 archive entries, 119 first-party files, and 13 dependencies. Report: `dist/verification-2.4.19.json`. SHA-256: `9be1f9102bf835f04e9bcc9e7c459bc567555425ce27763fe06341ed02b7118f`. The first run timed out while starting PHP for the local HTTP test; the isolated rerun and subsequent full verification passed. The initial report was recorded at `dist/verification-2.4.19-first-run.json`. Archived-plan speed recovery also has operator confirmation from a real installation; other scenarios in the test matrix remain subject to separate validation.

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

The historical 2.4.17 validation passed 27 suites, checks for 39 first-party PHP files, and verification of a ZIP containing 351 entries, 118 first-party files, and 13 pinned dependencies. Its report and hash are retained later in this changelog; the validation status of current candidate 2.4.38 is recorded above.

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

The current version is 2.4.38. Its validation status appears at the beginning of this changelog. Archived-plan recovery confirmation belongs to 2.4.19 and retains its historical scope. Fresh lifecycle evidence retires obsolete intent and queues guarded removal before releasing physically owned addresses. Unknown evidence remains an incident for that service. The separate local-cleanup review retains its limited scope and private backup. A duplicate-reservation migration barrier still requires resolving stored assignments and reloading. Backups remain downloadable from System. The [real-environment test guide](PRUEBAS_REALES.md) contains 242 scenarios and does not claim production validation.

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

1. Retain the previous installer and private copies of `data/data.db` and `data/jobs.db`. Legacy SQLite panel backups contain `data.db` only. Since 2.4.37, full ZIP backups also preserve the supported queue, cache and operational state.
2. Pause workers and existing deliveries while replacing the package. Check the migration before resuming them.
3. Check webhook mode. By default, store only the key in `webhookSecret` and send it from UISP as well; the plugin does not modify the endpoint. To operate without a key, explicitly enable compatibility mode as described in the [guide](WEBHOOK_SETUP_EN.md).
4. If web PHP retains the old entry point with `opcache.validate_timestamps=0`, invalidate its cache or restart that service. The CLI and browser reloads do not refresh web OPcache.
5. In a lab, check creation, plan changes, suspension, payment, router/IP changes, partial failures, offline routers, repeated deliveries, certificates, worker restart, and restoration.
6. Verify IPv4/IPv6, firewall rules, FastTrack, and dynamic queue placement after reconnection. Restoring SQLite does not undo changes already applied to the router.

Local regressions use PHP 8.5.11, temporary databases, mocks, and local sockets. Composer's resolver retains the declared PHP 7.4 compatibility, but the candidate still requires execution on the PHP version included in the target UISP installation.

## Known outstanding work

- Visual redesign is deferred until the final stage: compact elements across views and a notification center for warnings, retaining access to details and queue priority. The 2.4.38 backup grouping and compact System sections do not complete that broader redesign.
- Physical removal of an individual suspension queue with unexpected dependencies can remain deferred; 2.4.36 reports a required retained resource as unconfirmed instead of presenting verified removal.
- Broaden periodic review beyond proven owned PPP/Hotspot accounts with no active work; migrations, adoptions and partial evidence retain separate review.
- Chronological ordering of distinct events still requires a reliable source revision. Fresh reads and the 2.4.37 pending-review inbox avoid applying stale snapshots without inventing that revision or assuming immediate UISP consistency.
- NMS topology writes remain disabled because earlier code could delete other services' nodes. Reads remain supported. Re-enabling writes requires a persistent `serviceId → deviceId` relationship and verified ownership.
- Real-environment testing across the installation's UISP/RouterOS versions, certificates, and network policies.
- Native-speaker review of the new translations.

The [draft PR description](PR_DESCRIPTION.md) presents the change for upstream review. This changelog documents implemented behavior and its limits; it does not claim that the PR is published or that all real-environment integration has been validated.
