# Jira Integration

## Project
- **Key:** `WPD`
- **Board:** https://procyoncreative.atlassian.net/jira/software/c/projects/WPD/boards/201
- **REST API base:** https://procyoncreative.atlassian.net/rest/api/3

## Board columns
The WPD board currently has: **Backlog → Selected for Development → In Progress → Done**. There is no QA / In Review column, so the GitHub workflow only transitions tickets to **Done** on PR merge (not to QA on PR open).

## MCP Server
- **Server:** `procyon_atlassian` (SSE)
- **URL:** https://mcp.atlassian.com/v1/sse
- Tools available as `mcp__procyon_atlassian__*` once Claude Code re-loads the project.
- **Note:** Atlassian's HTTP+SSE transport is being deprecated 2026-06-30. The replacement endpoint is `https://mcp.atlassian.com/v1/mcp` (Streamable HTTP). Update before that date.

## Auth
- **Email:** set as the `JIRA_EMAIL` repo secret. Use the Atlassian login email for procyoncreative.atlassian.net.
- **API token:** generate at https://id.atlassian.com/manage-profile/security/api-tokens
- **`JIRA_API_TOKEN`** is required as a repo secret. Never commit it. For local scripts, source it from `.env` (gitignored).
- **`JIRA_BASE_URL`** repo secret: `https://procyoncreative.atlassian.net`

Set the three secrets via:
```bash
gh secret set JIRA_BASE_URL --body "https://procyoncreative.atlassian.net"
gh secret set JIRA_EMAIL --body "your-atlassian-email"
gh secret set JIRA_API_TOKEN  # paste the token at the prompt
```

## Workflow Rules
- **No work without a ticket.** Every branch must reference a `WPD-NNN` Jira ticket. If one doesn't exist, create it before opening the branch.
- **Branch name format:** `WPD-NNN-short-description` (e.g. `WPD-3-ci-lando-and-jira-sync`).
- **Ticket requirements:**
  - **Hours estimate** in `timetracking.originalEstimate` (`30m`, `2h`, `1d`).
  - **Acceptance Criteria** including the mandatory line: `Use Red/Green TDD`.
- **PR merge → ticket moves to Done.** Handled automatically by `.github/workflows/jira.yml`.
- **WPD-2 manual transition** was used once to clean up a ticket created before the workflow was installed; future tickets transition automatically.

## CI Workflow
The `.github/workflows/jira.yml` action:
1. On PR open / synchronize / reopen / close: syncs ticket metadata (comment, labels) onto the PR via [`procyon-creative/jira-action-man@v1.0.0`](https://github.com/procyon-creative/jira-action-man).
2. On PR merge: transitions any referenced ticket to `Done`.

## Quick reference
- Create / edit a ticket via Claude: `/jira-ticket`
- Set up a new project: `/jira-setup <PROJECT_KEY>`
