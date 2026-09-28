# Client-Aligned Implementation Plan: Consolidated Survey Report

**Owner:** ENAF Survey Platform

**Client contact:** John Mwangangi, Planning, Monitoring and Evaluation Officer

**Plan date:** 28 September 2026

**Status:** The workbook redesign is implemented locally and automated checks pass. Production-scale benchmarking, deployment, and client acceptance remain pending.

## 1. Client request and intended outcome

The request raised on 18 September 2026 is to:

1. Generate and download **one consolidated report covering all groups and all active surveys**, instead of generating separate reports one group and one survey at a time.
2. Let the M&E team download the complete dataset and **analyse it by survey**.
3. Restore access to **individual participant-level survey responses**, so staff can open a participant and review their answers for analysis and follow-up.

The report must therefore show both the organization-wide picture and the survey/group breakdowns behind it. A workbook that merely contains records from many surveys, without making the survey and group dimensions usable, does not fully meet the intent.

## 2. What is wrong with the current workbook

The current workbook has four sheets: Survey Overview, Participation Funnel, Question Performance, and Member Responses. Production verification showed that the latest consolidated file contains 40,881 response rows, but its Member Responses sheet is a very wide cross-survey matrix (107 columns) and does not identify the participant's group. This makes comparisons difficult and creates a large, sparse-looking sheet.

The organization-wide overview also combines participants across surveys. It does not provide an explicit group-by-survey performance table. The report is therefore technically multi-survey, but not yet a clear, client-ready consolidated analysis.

The old empty workbook was a separate issue: the report mode was lost between queueing and generation. That handoff is fixed in commit `b6e015e`; retain a regression test for it while redesigning the workbook.

## 3. Reporting contract

### Scope

- Include all groups and all surveys whose status is `Active` at generation time. State that rule and the generation-time survey count in the scope/methodology sheet; do not silently include inactive/archived surveys.
- Include every configured group-survey assignment for an included active survey in coverage reporting, including assignments with zero dispatches/responses. Do not invent activity for group-survey combinations that were never configured.
- Include all participant response records in the detailed dataset, subject to the existing private-storage and per-user download authorization.
- Label the report generation timestamp, included statuses, record counts, and any legacy/unattributed data.

### Attribution and counting rules

- Use stable IDs (`survey_id`, `group_id`, `member_id`, `question_id`, dispatch-batch UUID) for joins and aggregation; use titles/names only as display labels.
- Attribute a dispatch to a group using the recorded dispatch batch and its `group_survey` dispatch metadata where available. Do not infer historical group attribution solely from a member's current group membership, since membership can change or overlap.
- For legacy records without a reliable dispatch-to-group link, mark the group as `Unattributed`; do not infer historical attribution from current membership or silently count it as verified group history.
- Count at the correct grain and state it beside each metric: participant, participant-survey, group-survey assignment, question, response, or SMS credit transaction. Do not label a distinct-member total as a survey-dispatch total.
- Preserve English/Swahili alternate-question handling while displaying one canonical question row per logical question, associated with its survey.
- Credit totals must use only transactions attributable to the report scope. Show unattributed credit usage separately instead of implying it belongs to a specific group or survey.

## 4. Target workbook structure

1. **Read Me & Scope**
   - Purpose, generation time, active-survey/all-group scope, filters (all by default), row counts, metric definitions, attribution caveats, and notes on legacy records.
   - Clear instructions for filtering the data sheets in Excel.

2. **Executive Summary**
   - Organization-wide KPIs with precise labels and denominators: configured group-survey assignments, recorded queued recipient entries, participants with progress, unique participants observed, respondents, completed participant-surveys, in-progress, drop-offs, response/completion rates, and attributable SMS credits.
   - Show unique participants separately from total participant-survey records so multiple surveys do not get conflated.

3. **Survey Summary**
   - One row per survey, including active status, configured/dispatched groups, participants with progress, unique participants observed, responders, completions, in-progress/drop-offs, response and completion rates, response rows, and attributable credits.
   - Define survey rates against the unique observed-participant denominator; do not present queued recipient entries as unique people.
   - Enables the client's requested analysis by survey without generating separate workbooks.

4. **Group × Survey Performance**
   - One row per configured group-survey assignment, including zero-activity assignments.
   - Include group name/ID, survey name/ID/status, dispatch state/date/batch, recorded queued/skipped recipients, participants with linked progress, responders, completions, in-progress/drop-offs, rates, response rows, credits, and attribution quality.
   - Show all configured group-survey pairs for the active-survey scope, including zero-activity pairs. Never fabricate a full Cartesian set of assignments.

5. **Group Coverage**
   - One row for every group, including groups with no active survey assignment or activity.
   - Show current member count, number of configured active surveys, dispatches, participant progress records, and a clear coverage status.

6. **Question Performance**
   - One row per logical survey-question, never combining same-looking questions from different surveys.
   - Include survey, question order/text, observed-participant denominator, unique respondents, response rate, top answer distribution/counts, first/last response timestamps, and the full answer detail in Participant Responses.

7. **Participant Survey Summary**
   - One row per participant-survey (and group attribution where it is verifiable): participant name/ID/phone, survey, group, dispatch batch, current/completed status, response count, completion timestamp, and attribution source.
   - Avoid duplicate rows caused by multiple response records; define how a participant in multiple groups is represented.

8. **Participant Responses**
   - Normalized, filterable detail: one row per participant-survey-question response, with survey, group/attribution source, participant identifiers, question order/text, answer, responded-at timestamp, progress status, and dispatch batch where available.
   - Do not use one column for every question across every survey or fill unrelated survey questions with `N/A`. This keeps the sheet interpretable and avoids a huge sparse matrix.
   - Keep this sheet consistent with the participant-level Survey Responses page: the displayed question must be the actual survey question, not the SMS prompt/inbox message.

## 5. Implementation workstreams

### A. Establish and enforce the report data contract

- Keep the explicit queued report mode (`report_mode=consolidated`) and test that it survives serialization into the job.
- Replace implicit “first ID in `survey_ids`” behavior with an explicit scope/mode parser. A consolidated job must not collapse to the first survey when its normalized credit filters contain all survey IDs.
- Build reusable, keyed scopes for all surveys, all groups, configured group-survey pairs, and their dispatch batches.
- Add explicit response and progress query methods for each reporting grain; prevent response joins by phone alone when a response can otherwise be matched to a different survey/progress.

### B. Build correct survey/group aggregates

- Aggregate by stable `(survey_id, group_id)` and survey IDs before rolling up to organization totals.
- Derive denominators from recorded dispatch/group-survey metadata when reliable; document fallback behavior for older dispatches.
- Track unique-member totals separately from participant-survey totals.
- Map question variants to one canonical survey-question while retaining responses to either language variant.
- Keep attribution-quality and unmatched-record counts visible so legacy data gaps are not concealed.

### C. Redesign the workbook writer

- Replace the wide cross-survey question matrix with normalized participant response rows.
- Add the eight sheets in Section 4, with consistent titles, clear date/number formats, Excel filters/tables, frozen headers, usable column widths, and stable ordering.
- Add a “Survey” and “Group” dimension to every analysis/detail row where it applies.
- Make sheet names and headings human-readable; avoid internal IDs as the only labels.
- Make report row counts describe the sheet/record grain (or expose per-sheet counts), rather than calling every progress row a member.
- Preserve private export storage, ownership authorization, completion notification, and failure reporting.

### D. Keep the generation usable at production scale

- Stream data in bounded chunks/cursors; avoid loading the full response set or full workbook into PHP memory.
- Aggregate counts in SQL or bounded maps rather than repeatedly scanning all records per group/survey.
- Avoid writing blank/N/A cells for non-applicable survey questions.
- Benchmark using production-scale counts (the last verified export contained 40,881 response rows); record generation time, final size, peak memory, and per-sheet row counts.
- Set a generation-time target after measuring the redesigned workbook. The previous wide workbook took about 20–22 minutes, so the redesign should materially reduce that time without omitting data.

### E. Maintain participant-level access in the application

- Keep the Survey Responses table and the single-response and all-responses participant actions.
- Ensure participant identity lookup handles phone normalization consistently.
- Ensure the participant-level workbook sheet and UI show survey, participant, actual question, response, and timestamp; group attribution should be shown only with its source/quality.

## 6. Regression and acceptance tests

### Automated tests

- A consolidated report created from the Survey Reports action retains the consolidated mode through the queued job and includes all surveys, not only the first ID.
- Fixture with at least two surveys and two groups verifies survey totals, group-survey totals, organization totals, and zero-activity assignments independently.
- Include active and inactive surveys (the consolidated scope must include active and exclude inactive), groups with no activity, participant membership in multiple groups, duplicate phone formats, missing dispatch-batch links, and a progress record with no response.
- English/Swahili variants produce one canonical question row while either variant's answer is associated correctly.
- Participant Responses has one row per real response and includes survey and group/attribution fields; it does not produce irrelevant `N/A` columns for questions from other surveys.
- Credit totals are attributed only when the underlying transaction linkage supports it; unmatched credits are reported separately.
- Verify workbook sheet names, headers, row counts, filters/freeze panes, non-empty metrics, authorization, and report failure state.
- Keep existing tests for single-survey/single-group comprehensive reports passing.

### Production acceptance checklist

- Generate a fresh consolidated workbook from the UI after deployment; do not treat the old empty workbook or the current wide workbook as the acceptance artifact.
- Confirm every active survey and every group is represented in the scope/coverage sheets, including zero-activity assignments; confirm inactive surveys are explicitly excluded.
- Reconcile organization totals to the survey and group-survey breakdowns; check distinct participants separately from participant-survey records.
- Inspect representative rows from each sheet, including a participant response and its survey/group attribution.
- Open the workbook in Excel/LibreOffice and confirm filtering, frozen headers, column widths, and sheet usability.
- Confirm the file is completed, non-empty, downloadable by its owner, and the notification links to the correct workbook.
- Mark earlier misleading/empty exports as superseded in the UI or otherwise make the newest verified report unambiguous; retain old files only according to the existing retention policy.

## 7. Delivery sequence

1. Finalize the data contract and aggregation definitions above.
2. Implement attribution-safe survey/group aggregations and regression fixtures.
3. Implement the normalized workbook sheets and update per-sheet row-count metadata.
4. Optimize and benchmark the export with production-scale data.
5. Run the automated reporting tests and existing single-scope tests.
6. Deploy through the existing GitHub Actions workflow.
7. Generate and inspect a new production workbook against the acceptance checklist.
8. Only then send the client the updated report instructions and describe the report as complete.

## 8. Out of scope

- Replacing the existing Survey Responses interface; it remains the interactive participant-level workflow.
- Changing survey dispatch behavior, SMS reminders, or participant responses.
- Claiming historically exact group attribution for records that lack a reliable dispatch-batch/group link; those must remain flagged as inferred or unattributed.
