# Implementation Plan: Consolidated Reporting & Participant-Level Responses

## Client Request (John Mwangangi, ENAF M&E Officer)

Two enhancements requested on 18 September 2026, follow-up received 27 September 2026:

1. **Consolidated survey report** — ability to generate and download a single report covering all groups and all surveys at once (currently limited to one group + one survey).
2. **Individual participant-level survey responses** — the Survey Responses section should display actual responses per participant with the ability to open and review individual answers (previously available, currently not showing as expected).

---

## Requirement 1: Consolidated All-Groups-All-Surveys Report

### Current state
- `ComprehensiveSurveyReportService::scope()` requires exactly one survey ID and one group ID — it throws `RuntimeException` if either is missing.
- `CreditUtilizationWorkbookWriter::write()` calls `scope()` then generates a 4-sheet workbook (Overview, Participation Funnel, Question Performance, Member Responses) scoped to that single survey+group.
- The "Download comprehensive report" button on `SurveyReports` is only visible when both `survey_id` and `group_id` filters are selected.
- The workbook is queued via `GenerateCreditUtilizationReportJob` and downloaded from the "Recent report downloads" section.

### What needs to change

#### 1a. Make survey and group optional in `ComprehensiveSurveyReportService::scope()`

**File:** `app/Services/ComprehensiveSurveyReportService.php`

- Remove the `RuntimeException` when survey/group are absent.
- When no survey is specified, load all active surveys. When no group is specified, don't filter by group.
- Return a `survey` key that can be either a single `Survey` model or a Collection of surveys. Same for `group`.
- Adjust `canonicalQuestions()` and `legacyResponseQuestions()` to accept either a single survey or a collection — merge questions across all surveys, prefixed with the survey title to avoid confusion.
- Adjust `memberQuery()` to accept `?int $groupId` (null = all members).
- Adjust `streamMemberResponses()` and `streamLegacyMemberResponses()` to accept `?int $surveyId` and `?int $groupId` — when null, don't filter by that dimension.

#### 1b. Update `CreditUtilizationWorkbookWriter` for multi-survey/group output

**File:** `app/Services/CreditUtilizationWorkbookWriter.php`

- When multiple surveys are in scope, add a "Survey" column to the Member Responses sheet so the M&E team can filter/pivot by survey.
- The Overview sheet should list each survey with its own participation row instead of a single survey header.
- The Question Performance sheet should include the survey name in each row.
- Credit utilization filters should not be scoped to a single survey/group when none is selected.

#### 1c. Add "Download all surveys report" button to `SurveyReports` page

**File:** `app/Filament/Pages/SurveyReports.php`

- Add a second header action: "Download consolidated report" (or make the existing one work without filters).
- When no filters are applied, queue the report with null survey/group → all surveys, all groups.
- Keep the existing per-survey-per-group button when filters are applied.

#### 1d. Update `GenerateCreditUtilizationReportJob` (minimal)

**File:** `app/Jobs/GenerateCreditUtilizationReportJob.php`

- No changes needed — it already passes `$report->filters` to the writer. The filters just need to support null survey/group IDs.

#### 1e. Filename adjustment

**File:** `app/Filament/Pages/SurveyReports.php` → `queueComprehensiveReport()`

- When no survey/group is selected, use filename like `echo_net_africa_all_surveys_consolidated_report_2026_09_27_His.xlsx`.

### Performance consideration
- 9 surveys, 3,490 groups, 40,887 survey progress records, 30,956 survey responses.
- The streaming chunk-based approach already handles large datasets. Multi-survey will be heavier but should still complete within the job timeout (3600s).
- Consider adding a survey-level loop in the writer: iterate surveys, and for each, stream its members/responses. This avoids one massive query and lets each survey's data land in the same workbook with a "Survey" column.

---

## Requirement 2: Individual Participant-Level Survey Responses

### Current state
- `SurveyResponseResource` has a `ViewAction` that opens the form schema in read-only mode.
- The form schema shows: Survey (select), MSISDN (text), inbox_id labeled "Question" (select showing SMS message text, NOT the actual survey question), and survey_response (text).
- The actual `question_id` field is **commented out** in the form (lines 56-62). This is likely why the client says responses "were available previously but no longer showing as expected" — the question field was replaced with the inbox message field.
- The table columns include `question.question` (correct) but the view form shows `inbox.message` instead.
- There are no table filters (survey, phone, date range) on the ListSurveyResponses page.
- The `SurveyResponsesRelationManager` on the Member resource shows responses correctly (survey title, question, response, date) but has no ViewAction.

### What needs to change

#### 2a. Fix the ViewAction form to show actual question, not SMS message

**File:** `app/Filament/Resources/SurveyResponseResource.php`

- Uncomment the `question_id` select field and remove or relabel the `inbox_id` field.
- Use Infolist entries instead of form fields for the view (cleaner read-only display). Add `Infolist` trait to the resource with:
  - Survey title (text)
  - Member name (text, derived from msisdn→member relationship)
  - Phone/MSISDN (text)
  - Question (text, from `question.question`)
  - Response (text)
  - Date (datetime)
  - The SMS message that was sent (optional, from inbox relationship)

#### 2b. Add filters to the Survey Responses table

**File:** `app/Filament/Resources/SurveyResponseResource.php`

- Add `Select::make('survey_id')` filter — filter by survey.
- Add `Select::make('question_id')` filter — dependent on survey, filter by question.
- Add `TernaryFilter` or `Select` for member association — show only responses with a matched member.
- Add `Filter::make('created_at')` — date range filter.

#### 2c. Add a "View Responses" action that shows all responses for a member

**File:** `app/Filament/Resources/SurveyResponseResource.php`

- Add a `ViewAction` or `Action` that opens a modal/page showing all responses for a specific participant (msisdn), grouped by survey and question, in chronological order.
- This gives the M&E team the "open a participant, review their individual answers" workflow the client described.

#### 2d. Add a "Participant View" relation manager or page

Either:
- **Option A (simpler):** Add a `ViewAction` on the table that opens an Infolist view showing the single response with full context (survey, question, response, date, member details).
- **Option B (richer):** Add a "View Participant Responses" action that opens a page listing all responses for that MSISDN, grouped by survey, showing each question and answer in sequence.

**Recommended: Option B** — it directly addresses the client's need to "see a participant, open their response, and review their individual answers for detailed analysis."

#### 2e. Improve table columns for readability

**File:** `app/Filament/Resources/SurveyResponseResource.php`

- Make `member.name` visible by default (currently shown but check if the relationship resolves correctly — `SurveyResponse::member()` uses `msisdn` → `phone` which may not match if phone formats differ).
- Add `survey.title` as visible by default.
- Keep `question.question`, `survey_response`, `created_at` visible.
- Add a "Status" column showing whether the member completed the survey (from SurveyProgress).

---

## Implementation Order

| Step | Task | Files | Est. effort |
|------|------|-------|-------------|
| 1 | Fix ViewAction form — restore question_id field, add Infolist | `SurveyResponseResource.php` | Small |
| 2 | Add table filters (survey, date, question) | `SurveyResponseResource.php` | Small |
| 3 | Add "View Participant Responses" action (Option B) | `SurveyResponseResource.php`, new view | Medium |
| 4 | Make `ComprehensiveSurveyReportService::scope()` accept null survey/group | `ComprehensiveSurveyReportService.php` | Medium |
| 5 | Update workbook writer for multi-survey output | `CreditUtilizationWorkbookWriter.php` | Medium |
| 6 | Add "Download consolidated report" button without filters | `SurveyReports.php` | Small |
| 7 | Test on prod with real data | — | Small |
| 8 | Deploy | — | Small |

### Steps 1-3 can be done first (participant-level responses) since they're independent of the consolidated report work. Steps 4-6 build on the existing report infrastructure.

---

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Phone number format mismatch between `survey_responses.msisdn` and `members.phone` | Already handled by `phoneVariants()` in the service; verify Infolist member lookup uses the same normalization |
| Large workbook generation timeout for all surveys | Keep chunk-based streaming; add survey-level loop; job timeout is 3600s |
| Memory pressure with 40K+ progress records | Already chunked at 250-500 records; no change needed |
| Member name not resolving in SurveyResponse table | The `member()` relationship uses `msisdn` → `phone` direct match; add `normalizePhoneNumber()` fallback or use a accessor |
| Question Performance sheet with mixed surveys may be confusing | Add "Survey" column to every sheet; group questions by survey in the output |