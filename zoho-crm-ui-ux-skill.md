---
name: zoho-crm-ui-ux
description: >
  Build CRM interfaces using the interaction model, information density, navigation
  hierarchy, record-management patterns, and UX conventions of modern Zoho CRM NextGen.
  Use this skill whenever designing or implementing CRM dashboards, lead/contact/account/deal
  modules, list views, record detail pages, forms, pipelines, activities, reports, settings,
  teamspaces, filters, tables, or other business-management screens.
version: 1.0.0
tags:
  - crm
  - ui
  - ux
  - zoho-inspired
  - dashboard
  - enterprise
  - sales
  - leads
  - contacts
  - deals
---

# Zoho CRM UI & UX Implementation Skill

## 1. Purpose

Use this skill to design a polished, enterprise CRM interface strongly inspired by the
information architecture and interaction patterns of Zoho CRM NextGen.

The goal is NOT to copy Zoho branding, logos, illustrations, proprietary icons, or exact
visual assets.

The goal IS to reproduce the qualities that make the interface effective:

- compact enterprise information density
- persistent left-side navigation
- fast access to CRM modules
- strong list/table workflows
- contextual actions near records
- multiple record visualization modes
- quick create from anywhere
- global search
- advanced filtering
- clean record detail pages
- related records and activity history
- pipeline/Kanban workflows
- predictable forms
- strong bulk actions
- low-friction navigation
- role/team-oriented workspaces
- restrained visual styling

When requirements are unclear, prefer usability, information density, and speed over
decorative visuals.

---

# 2. Core Design Philosophy

The application should feel like a serious business operating system.

Prioritize:

1. Fast scanning
2. Minimal navigation depth
3. High information density without visual clutter
4. Clear hierarchy
5. Consistent actions
6. Strong table usability
7. Context preserved while moving between records
8. Predictable patterns across modules
9. Keyboard-friendly workflows
10. Professional desktop-first UX

Avoid:

- huge cards
- excessive gradients
- oversized headings
- excessive rounded corners
- consumer-app aesthetics
- unnecessary animation
- glassmorphism
- giant empty spaces
- hiding important controls behind multiple menus
- different interaction patterns for similar modules

---

# 3. Application Shell

Use a three-zone application shell.

```text
┌────────────────────────────────────────────────────────────────────┐
│ TOP UTILITY BAR / GLOBAL SEARCH / CREATE / NOTIFICATIONS / PROFILE │
├───────────────┬────────────────────────────────────────────────────┤
│               │                                                    │
│ LEFT SIDEBAR  │                    MAIN PANE                       │
│               │                                                    │
│ Teamspace     │ Page header                                        │
│ Home          │ Toolbar                                            │
│ Leads         │ Filters / views                                    │
│ Contacts      │ Table / board / record                             │
│ Accounts      │                                                    │
│ Deals         │                                                    │
│ Activities    │                                                    │
│ Reports       │                                                    │
│ ...           │                                                    │
│               │                                                    │
└───────────────┴────────────────────────────────────────────────────┘
```

## Main shell rules

- Sidebar remains visible on desktop.
- Sidebar can collapse to icon-only mode.
- Main pane must consume remaining horizontal space.
- Top utility actions remain available regardless of current module.
- Do not create separate visual shells for Leads, Contacts, Accounts, Deals, etc.
- Preserve module context when opening records.
- Favor 100vh layouts with internal scrolling rather than the entire browser page jumping.

---

# 4. Left Sidebar

The left sidebar is the primary navigation system.

## Recommended width

Expanded:
- 220–250px

Collapsed:
- 56–64px

## Sidebar hierarchy

Recommended structure:

```text
Workspace / Teamspace selector

Home

PRIMARY
- Leads
- Contacts
- Accounts
- Deals

ACTIVITIES
- Tasks
- Calls
- Meetings
- Appointments

COMMUNICATION
- Emails
- Messages

SALES OPERATIONS
- Products
- Quotes
- Sales Orders
- Invoices

AUTOMATION
- Workflows
- Sequences
- Blueprints / Processes

ANALYTICS
- Reports
- Dashboards

CUSTOM
- Custom Modules

Bottom
- Marketplace / Integrations
- Settings
- Collapse Sidebar
```

## Sidebar behavior

Each module item should support:

- icon
- text label
- active state
- optional count/badge
- optional overflow menu
- tooltip while sidebar is collapsed

Active state should be visible but subtle.

Recommended:
- light tinted background
- stronger text
- small accent indicator
- no large pill buttons

## Teamspace / workspace switcher

Place near the top.

It may contain:
- workspace icon
- workspace name
- dropdown chevron

Dropdown can expose:
- Sales
- Marketing
- Support
- Operations
- custom teams

Allow modules to vary by workspace if the product supports this.

---

# 5. Top Utility Bar

The top utility bar is global and should remain compact.

Recommended height:
- 52–60px

Contents:

```text
[optional breadcrumb/context]     [Global Search]     [+ Create]
                                                     [AI Assistant]
                                                     [Notifications]
                                                     [Calendar]
                                                     [Settings]
                                                     [Profile]
```

## Global Search

Global search is one of the highest-priority controls.

Use a prominent search field around 320–520px wide on large screens.

Placeholder examples:

```text
Search leads, contacts, deals...
Search CRM
Search anything
```

Search results should group results by module:

```text
LEADS
Sachin Tambe
...

CONTACTS
...

DEALS
...
```

Support:
- keyboard shortcut `/` or `Cmd/Ctrl + K`
- recent searches
- highlighted matching text
- arrow-key navigation
- Enter to open
- Escape to close

---

# 6. Quick Create

Provide a global `+` create action.

Possible quick-create menu:

```text
+ Lead
+ Contact
+ Account
+ Deal
+ Task
+ Meeting
+ Call
+ Note
```

Do not force users to navigate into a module before adding a record.

For short records, use a modal or side drawer.

For complex records, use a full create page.

---

# 7. Standard Page Header

Every module follows the same structural template.

```text
Breadcrumb / optional workspace
Module Name                 Primary action

View selector / saved view
Toolbar
Content
```

Example:

```text
Leads                                      + Create Lead

All Leads ▾          Search in Leads     Filter     ⋯

-------------------------------------------------------
[table / grid / board]
```

Header height should be compact.

Recommended title:
- 20–24px
- semibold

Avoid huge SaaS marketing-style titles.

---

# 8. Module Views

Support multiple ways to view the same records.

Recommended options:

- List
- Kanban
- Grid
- Timeline
- Chart
- Split
- Custom/Canvas-style view

Represent these as compact icons or a view dropdown.

Do not reload an entirely different application shell when switching view type.

---

# 9. List View — Highest Priority

The table/list view is the default work surface for most CRM modules.

## Structure

```text
┌─────────────────────────────────────────────────────────────────────┐
│ All Leads ▾   Search   Filter   Sort    Columns   View   + Create  │
├────┬───────────────┬────────────┬───────────┬────────┬─────────────┤
│ □  │ Lead Name     │ Company    │ Email     │ Status │ Owner       │
├────┼───────────────┼────────────┼───────────┼────────┼─────────────┤
│ □  │ ...           │ ...        │ ...       │ ...    │ ...         │
└────┴───────────────┴────────────┴───────────┴────────┴─────────────┘
```

## Table UX

Must support where relevant:

- sticky table header
- horizontal scrolling
- row selection
- select all
- sortable columns
- resizable columns
- column reordering
- customizable visible columns
- inline edit
- row hover actions
- row context menu
- pagination or efficient infinite loading
- bulk actions
- compact density
- saved views
- empty states
- loading skeletons

## Row density

Recommended:
- 40–48px row height

Do not use 64–80px rows unless the content genuinely needs them.

## Row hover actions

Show only on hover when possible:

```text
Edit
Email
Call
More
```

Keep permanent row clutter low.

---

# 10. Saved Views

Every major module should support saved views.

Examples:

```text
All Leads
My Leads
Today's Leads
Uncontacted Leads
Converted Leads
Recently Created
High Priority
```

View selector belongs near the module title.

Users should be able to:

- create view
- edit view
- duplicate view
- share view
- set default
- delete view
- pin frequently used views

A saved view includes:

```text
filters
sort
visible columns
column order
optional grouping
optional view type
```

---

# 11. Advanced Filters

Filtering should behave like a CRM tool, not a simple ecommerce filter.

Recommended filter drawer:

```text
FILTER LEADS

Owner
[ Me                         ▾ ]

Lead Status
[ ] New
[ ] Contacted
[ ] Qualified

Created Time
[ Last 30 days              ▾ ]

Deal Value
[ Greater than ] [ 100000 ]

+ Add condition

Match:
(o) ALL conditions
( ) ANY condition

[Reset]              [Apply]
```

Support operators:

- equals
- not equals
- contains
- does not contain
- starts with
- ends with
- is empty
- is not empty
- greater than
- less than
- before
- after
- between
- in last N days

Allow nested conditions only if product complexity requires them.

---

# 12. Bulk Selection Mode

When rows are selected, replace or augment the standard toolbar.

Example:

```text
3 selected    Update Field    Assign Owner    Email    Delete    More
```

Never hide how many records are selected.

Potential bulk actions:

- assign owner
- update field
- add tags
- send email
- add to campaign
- export
- merge
- delete
- convert
- run workflow

Destructive actions must require confirmation.

---

# 13. Kanban / Pipeline View

Use Kanban for stage/status workflows.

Ideal for:
- Deals
- Leads
- Opportunities
- Tickets
- custom processes

Structure:

```text
NEW              QUALIFIED          PROPOSAL           WON
₹4.2L            ₹8.7L              ₹11.4L             ₹16L
------------------------------------------------------------
[Card]            [Card]             [Card]              [Card]
[Card]            [Card]             [Card]
```

Each column should contain:

- stage/status name
- record count
- aggregate value where relevant
- optional add button
- cards

Card may show:

- record name
- company/contact
- deal amount
- owner
- close date
- priority
- tags
- small quick actions

Support drag-and-drop stage updates.

After drag:
- optimistic UI update
- backend mutation
- toast success
- rollback if request fails

For stage changes requiring mandatory data, open a compact modal asking only for missing required fields.

---

# 14. Grid View

Grid view should feel spreadsheet-like.

Use when users need:
- rapid data entry
- inline editing
- keyboard navigation
- copying/pasting cells
- mass correction

Interactions:

- double-click to edit
- Enter commits
- Escape cancels
- Tab moves right
- Shift+Tab moves left
- arrow keys navigate
- copy/paste where technically feasible

---

# 15. Split View

Use split view to let users browse records while inspecting details.

```text
┌──────────────────────┬───────────────────────────────────────┐
│ Record list          │ Selected record                       │
│                      │                                       │
│ Lead 1               │ Header                                │
│ Lead 2 ← selected    │ Contact details                       │
│ Lead 3               │ Activities                            │
│ Lead 4               │ Related records                       │
└──────────────────────┴───────────────────────────────────────┘
```

Recommended left panel:
- 300–380px

This pattern is excellent for:
- leads
- inboxes
- tasks
- support tickets

Preserve list scroll position as selected records change.

---

# 16. Record Detail Page

The record detail page is one of the most important UX surfaces.

## Recommended anatomy

```text
Breadcrumb / back

[Avatar/Icon]  Record Name                [Edit] [Email] [Call] [More]
               Company / subtitle
               Status / tags / owner

Key fields / business card

Tabs or anchored sections
------------------------------------------------------------
Overview
Timeline
Activities
Emails
Notes
Attachments
Related Records
```

## Record header

Display only the most important identifying information.

Example lead:

```text
Rahul Sharma
Acme Solar Pvt Ltd

Qualified   High Priority
Owner: Sachin
```

Actions:

```text
Edit
Send Email
Call
WhatsApp
Convert
More
```

Do not put 15 buttons permanently in the header.

Keep 2–4 primary actions visible and move secondary actions into More.

---

# 17. Record Summary / Business Card

Immediately under the header, surface 4–6 high-value fields.

Example:

```text
Email            Phone           Lead Source
rahul@...        +91...          Meta Ads

Owner            Created         Last Activity
Sachin           26 Oct          2 hours ago
```

Use labels with lower emphasis than values.

Allow inline edit when appropriate.

---

# 18. Detail Sections

Organize data into collapsible sections.

Example:

```text
Lead Information
-----------------------------
Lead Owner        Sachin
First Name        Rahul
Last Name         Sharma
Company           Acme
Lead Status       Qualified

Contact Information
-----------------------------
Email             ...
Phone             ...
City              Pune

Additional Information
-----------------------------
...
```

Desktop forms/detail data may use two columns.

Do not use cards inside cards for every section.

Prefer:
- subtle divider
- clear section title
- clean two-column field grid

---

# 19. Related List Navigation

Large records often have many related entities.

Use an anchored related-list menu or tab bar.

Example:

```text
Overview
Activities
Notes
Emails
Deals
Quotes
Invoices
Attachments
Products
History
```

If there are too many items:
- use vertical related-list navigation
- make it collapsible
- allow search if extremely large

The selected related section should remain obvious.

---

# 20. Timeline

Provide an activity timeline for historical context.

Example:

```text
TODAY

10:42 AM
📞 Call completed by Sachin
Outcome: Interested

9:18 AM
✉ Email sent
"Proposal for 5kW Solar System"

YESTERDAY

4:10 PM
Status changed
New → Qualified
```

Timeline events should distinguish:
- human activity
- communication
- automation
- field changes
- meetings
- notes

Use timestamp + actor + action + metadata.

Avoid oversized timeline illustrations.

---

# 21. Activities

Activities should be actionable from inside the record.

Types:
- Task
- Call
- Meeting
- Appointment
- Reminder

Suggested activity panel:

```text
Upcoming

Today 4:00 PM
Call Rahul about proposal
Owner: Sachin

Tomorrow 11:00 AM
Demo Meeting
```

Allow:
- mark complete
- reschedule
- edit
- delete
- create follow-up

When an activity completes, make follow-up creation easy.

---

# 22. Create/Edit Forms

Enterprise CRM forms can be large; design accordingly.

## Layout

Use:

- section headers
- two-column layout on desktop
- one-column layout on mobile
- consistent labels
- clear required indicators
- sticky footer actions for long forms

Example:

```text
CREATE LEAD

Lead Information
--------------------------------
First Name          Last Name *
Company             Lead Source
Status              Owner

Contact Information
--------------------------------
Email               Phone
Mobile              Website

Address Information
--------------------------------
Street
City                State
Country             Postal Code

[Cancel]                         [Save & New] [Save]
```

## Form rules

- labels above fields or consistently aligned
- do not mix label styles
- required fields marked clearly
- validation shown near field
- validation should explain how to fix the issue
- preserve entered data after validation failure
- auto-save drafts only when product behavior supports it
- warn before abandoning dirty forms

## Dynamic forms

Support rule-based visibility where useful:

```text
IF Lead Source = "Partner"
SHOW Partner Name
MAKE Partner Commission required
```

Only show fields relevant to the current context.

---

# 23. Form Controls

Standardize controls.

## Input heights

Recommended:
- 34–40px desktop

## Supported CRM controls

- text
- textarea
- email
- phone
- number
- currency
- percent
- date
- datetime
- select
- multi-select
- checkbox
- toggle
- radio
- tags
- user/owner picker
- lookup field
- address
- URL
- attachment
- rich text
- subform/table

## Lookup fields

Lookup controls are important in CRM.

Examples:
- Contact → Account
- Deal → Contact
- Invoice → Account

Lookup behavior:
- typeahead search
- recent records
- open record preview
- create new linked record when permitted
- clear selection

---

# 24. Modal vs Drawer vs Full Page

Use:

### Small Modal
For:
- confirmation
- quick field update
- simple task
- stage requirement
- short quick create

### Right Drawer
For:
- record preview
- filter configuration
- medium quick create
- contextual detail without losing list

Recommended width:
- 420–560px

### Full Page
For:
- long create/edit forms
- reports
- dashboards
- settings
- automation builders
- complex record detail

Do not open full pages for tiny tasks.

---

# 25. Search Inside a Module

Provide module-level search separately from global search.

Example:

```text
Search Leads
```

The search should operate within the currently selected module/view.

Global search and module search should be visually distinguishable.

---

# 26. Empty States

Empty states should be functional.

Bad:
```text
Nothing here.
```

Better:

```text
No leads yet

Create your first lead or import existing records.

[+ Create Lead]   [Import]
```

Filtered empty state:

```text
No records match these filters.

[Clear filters]
```

---

# 27. Loading States

Use skeletons matching the final layout.

For tables:
- skeleton rows

For record detail:
- skeleton title
- skeleton key fields
- skeleton sections

Avoid blocking the entire interface with a centered spinner unless necessary.

---

# 28. Error States

Errors must be local when possible.

Examples:

Inline:
```text
Enter a valid email address.
```

Toast:
```text
Lead updated successfully.
```

Failure:
```text
Couldn’t update the lead. Try again.
```

For optimistic changes:
- rollback
- explain failure
- preserve user context

---

# 29. Notifications and Toasts

Use compact toasts in a consistent corner.

Types:
- success
- warning
- error
- info

Do not show a success modal for routine actions.

Example:
```text
✓ Lead assigned to Priya
```

---

# 30. Dropdown Menus

Use dropdowns for secondary actions.

Standard overflow menu:

```text
Edit
Clone
Print
Export
Change Owner
Add Tags
----------------
Delete
```

Destructive actions should:
- appear last
- use destructive styling
- require confirmation when impactful

---

# 31. Context Menu

Optional right-click context menu can improve power-user workflows.

Possible actions:

```text
Open
Open in new tab
Edit
Assign owner
Add task
Email
Copy record link
Delete
```

Never make right-click the only way to access an action.

---

# 32. Statuses, Tags, and Badges

Use understated badges.

Examples:

```text
New
Contacted
Qualified
Proposal Sent
Won
Lost
Overdue
High Priority
```

Avoid rainbow UI.

Color is semantic, not decorative.

Suggested semantic concept:

- blue = informational/current
- green = successful/won/completed
- amber = warning/pending
- red = danger/lost/overdue
- gray = inactive/neutral
- purple = optional special/process state

Always include text; never rely on color alone.

---

# 33. Visual Design Tokens

This should be Zoho-inspired in density and structure, but use your own brand system.

Example neutral design tokens:

```css
:root {
  --bg-app: #f7f8fa;
  --bg-surface: #ffffff;
  --bg-subtle: #f4f6f8;
  --bg-hover: #f1f5f9;

  --text-primary: #1f2937;
  --text-secondary: #667085;
  --text-muted: #98a2b3;

  --border: #e4e7ec;
  --border-strong: #d0d5dd;

  --primary: #2563eb;
  --primary-hover: #1d4ed8;
  --primary-soft: #eff6ff;

  --success: #16a34a;
  --warning: #d97706;
  --danger: #dc2626;

  --radius-sm: 4px;
  --radius-md: 6px;
  --radius-lg: 8px;

  --shadow-menu: 0 8px 24px rgba(15, 23, 42, .10);
}
```

For BizCopilot-style branding, primary can instead use the product's own brand blue/teal system.

---

# 34. Radius Rules

Enterprise CRM should not look excessively bubbly.

Recommended:

- inputs: 4–6px
- buttons: 4–6px
- cards: 6–8px
- popovers: 6–8px
- modal: 8–12px

Avoid:
- 20px+ cards
- pill buttons everywhere

Pills are appropriate mainly for:
- tags
- status
- segmented controls

---

# 35. Typography

Recommended fonts:

- Inter
- system-ui
- SF Pro
- Roboto
- Geist

Example scale:

```text
Page title           22px / 600
Section title        15–16px / 600
Body                 13–14px / 400
Table text           13px / 400
Label                12–13px / 500
Meta/help            11–12px / 400
```

CRM interfaces benefit from slightly smaller typography than marketing websites.

Use line-height generously enough for readability.

---

# 36. Spacing System

Base unit:
- 4px

Common spacing:
- 4
- 8
- 12
- 16
- 20
- 24
- 32

Recommended page padding:
- 20–24px desktop
- 16px tablet
- 12–16px mobile

Avoid random spacing values.

---

# 37. Buttons

Types:

### Primary
Used for the main page action.

Examples:
- Create Lead
- Save
- Send
- Apply

### Secondary
Examples:
- Cancel
- Edit
- Import

### Ghost
Examples:
- icon toolbar actions
- view controls

### Destructive
Examples:
- Delete
- Remove

Recommended height:
- 34–38px

Do not use multiple strong primary buttons next to each other unless necessary.

---

# 38. Icons

Use a consistent icon system such as:

- Lucide
- Phosphor
- Material Symbols

Do NOT mix several icon families.

Recommended size:
- 16px standard
- 18px primary toolbar
- 20px sidebar if needed

Icons should generally be paired with labels for important actions.

---

# 39. Dashboard / Home

CRM home should answer:

- What requires my attention?
- What am I working on?
- How is my pipeline performing?
- What is overdue?
- What changed recently?

Recommended layout:

```text
Good morning, Sachin

[KPI] Leads Today
[KPI] Open Deals
[KPI] Pipeline Value
[KPI] Tasks Due

My Open Activities
---------------------------------

Sales Pipeline
---------------------------------

Recent Leads
---------------------------------

Overdue Follow-ups
---------------------------------
```

Cards should be compact and useful.

Avoid turning the dashboard into a visual poster.

---

# 40. KPI Cards

A KPI card should contain:

```text
Open Deals
42
₹18.4L
↑ 12% vs last month
```

Recommended:
- compact height
- 1 core number
- optional delta
- clickable to filtered list

Never place meaningless icons or decorative charts in every KPI card.

---

# 41. Reports

Report list:

```text
Report Name
Module
Created By
Last Modified
Folder
```

Report builder may include:

- module/data source
- fields
- filters
- grouping
- aggregation
- chart
- preview

Keep the builder structured into steps or panels.

---

# 42. Dashboards

Dashboard editing should support:

- widget grid
- resize
- drag/drop
- KPI widget
- funnel
- bar chart
- line chart
- pie/donut
- table
- target/achievement

Use charts only when visualization adds decision value.

---

# 43. Settings UX

Settings should use a different navigation hierarchy from normal CRM modules.

Suggested settings shell:

```text
Settings

GENERAL
- Company
- Users
- Roles
- Profiles
- Teams

CUSTOMIZATION
- Modules & Fields
- Layouts
- Pipelines
- Templates

AUTOMATION
- Workflow Rules
- Assignment Rules
- Approvals
- Webhooks

COMMUNICATION
- Email
- WhatsApp
- Telephony

DATA
- Import
- Export
- Backup
- Audit Log

DEVELOPER
- APIs
- Webhooks
- Functions
- OAuth / Apps
```

Settings should include search.

---

# 44. Module & Field Builder

If your CRM supports admin customization, emulate the low-code pattern.

Layout:

```text
┌──────────────────┬─────────────────────────────────────────────┐
│ FIELD TOOLBOX    │              FORM LAYOUT                   │
│                  │                                             │
│ Single Line      │  Lead Information                           │
│ Number           │  [First Name] [Last Name]                   │
│ Currency         │  [Company]    [Owner]                       │
│ Date             │                                             │
│ Lookup           │  Contact Information                        │
│ ...              │  [Email]      [Phone]                       │
└──────────────────┴─────────────────────────────────────────────┘
```

Support:
- drag/drop fields
- create section
- single/two-column sections
- required
- unique
- field permissions
- validation
- conditional visibility
- reorder

---

# 45. Pipeline Configuration

Admin should be able to configure:

```text
Pipeline: Enterprise Sales

New
Qualification
Demo
Proposal
Negotiation
Won
Lost
```

Support:
- reorder stages
- probability
- stage color
- required fields
- automation trigger
- closed/won semantics
- closed/lost semantics

---

# 46. Responsive Behavior

Zoho-style enterprise CRM is desktop-first, but responsive behavior must remain usable.

## ≥ 1440px
- full sidebar
- wide tables
- 2-column record fields
- optional auxiliary panels

## 1024–1439px
- sidebar may stay expanded or become narrower
- reduce table columns
- maintain core actions

## 768–1023px
- collapsible sidebar
- horizontal table scrolling
- drawers for filters
- 1–2 column forms

## < 768px
- sidebar becomes mobile navigation drawer
- tables transform into compact record lists/cards when necessary
- forms become one column
- primary action remains accessible
- avoid rendering 12-column desktop tables squeezed into mobile

---

# 47. Accessibility

Implement at minimum:

- semantic HTML
- visible focus states
- keyboard navigation
- labels for every form field
- ARIA labels for icon-only buttons
- sufficient contrast
- Escape closes dialogs
- focus trapped inside modal
- focus returns to triggering element after modal close
- no color-only status meaning
- 44px touch targets on mobile where practical

---

# 48. Keyboard UX

Recommended shortcuts:

```text
/ or Ctrl/Cmd+K    Global Search
C                  Quick Create (optional)
Esc                Close modal/drawer
E                  Edit record (optional)
J / K              Next/previous row (optional)
Enter              Open selected record
```

Do not conflict with browser shortcuts or text entry.

Keyboard shortcuts should be discoverable in a help dialog.

---

# 49. Permission-Aware UI

CRM interfaces are role-driven.

Never show actions that the user cannot perform unless there is a deliberate reason to show a disabled action.

Permissions may control:

- module visibility
- record visibility
- field visibility
- field editability
- delete
- export
- import
- assignment
- automation
- admin customization

The backend must enforce permissions too.

UI hiding is not security.

---

# 50. Auditability

Enterprise CRM actions should be traceable.

Important changes should record:

- actor
- timestamp
- old value
- new value
- source
- automation/manual context

Expose these in:
- timeline
- audit log
- history tab

---

# 51. Common CRM Module Templates

## Leads

Default columns:
- Name
- Company
- Phone
- Email
- Source
- Status
- Owner
- Created Time
- Last Activity

Primary actions:
- Create Lead
- Import
- Assign
- Convert

Views:
- list
- kanban by lead status
- grid
- split

## Contacts

Default columns:
- Name
- Account
- Email
- Phone
- Owner
- Last Activity

Related:
- Deals
- Activities
- Emails
- Quotes
- Invoices

## Accounts

Default columns:
- Account Name
- Industry
- Phone
- Website
- Owner
- Open Deals
- Revenue

Related:
- Contacts
- Deals
- Activities
- Quotes
- Invoices

## Deals

Default columns:
- Deal Name
- Account
- Stage
- Amount
- Closing Date
- Probability
- Owner
- Last Activity

Default alternate view:
- Kanban by Stage

Summary:
- pipeline total
- weighted total
- forecast

## Tasks

Columns:
- Subject
- Related To
- Due Date
- Status
- Priority
- Owner

Default filters:
- Today
- Overdue
- Upcoming
- Completed

---

# 52. Interaction Pattern — Create Lead

```text
User clicks + Create
→ chooses Lead
→ quick-create drawer or full form
→ fills fields
→ Save
→ optimistic/fast feedback
→ toast "Lead created"
→ record opens or user remains in previous context depending on action
```

Provide:
- Save
- Save & New

Do not clear the form before successful persistence.

---

# 53. Interaction Pattern — Update Record

```text
Open record
→ click Edit or inline field
→ update
→ save
→ detail updates immediately
→ timeline optionally records field change
→ toast success
```

If update fails:
- preserve entered changes
- explain failure
- allow retry

---

# 54. Interaction Pattern — Convert Lead

Example flow:

```text
Lead
→ Convert
→ Create / Link Contact
→ Create / Link Account
→ Optional Create Deal
→ Confirm
→ Conversion succeeds
```

Use a purpose-built conversion dialog, not a generic form.

Make consequences explicit.

---

# 55. Interaction Pattern — Follow-up

After:
- call
- meeting
- email
- status update

Offer:
```text
+ Schedule follow-up
```

This reduces forgotten leads.

A CRM should proactively support next actions.

---

# 56. AI Features

If the product contains AI, integrate it contextually.

Good locations:
- record page
- search
- compose panel
- lead summary
- suggested next action
- duplicate detection
- field extraction

Examples:

```text
Summarize this lead
Draft follow-up
Suggest next step
Extract requirements
Score opportunity
```

Do not make the entire application look like a chatbot.

CRM data remains primary.

---

# 57. Recommended Component Architecture

For React / Next.js:

```text
components/
  shell/
    AppShell
    Sidebar
    Topbar
    TeamspaceSwitcher
    GlobalSearch

  crm/
    ModuleHeader
    ViewSwitcher
    SavedViewSelector
    FilterDrawer
    BulkActionBar
    RecordTable
    RecordRow
    RecordPreviewDrawer
    RecordHeader
    RecordSummary
    RecordSection
    RelatedList
    ActivityTimeline
    KanbanBoard
    KanbanColumn
    KanbanCard
    GridView

  forms/
    FormSection
    LookupField
    OwnerPicker
    TagPicker
    CurrencyInput
    DateTimePicker
    DynamicFieldRenderer

  common/
    Button
    IconButton
    Dropdown
    Modal
    Drawer
    Tooltip
    Badge
    Tabs
    EmptyState
    Skeleton
    Toast
```

---

# 58. Suggested URL Architecture

```text
/app
/app/home

/app/leads
/app/leads/:id
/app/leads/:id/edit

/app/contacts
/app/contacts/:id

/app/accounts
/app/accounts/:id

/app/deals
/app/deals/:id

/app/tasks
/app/calls
/app/meetings

/app/reports
/app/dashboards

/app/settings
/app/settings/modules
/app/settings/modules/:moduleId
/app/settings/automation
/app/settings/users
```

Use query parameters for view state when useful:

```text
/app/leads?view=my-leads
/app/deals?mode=kanban&pipeline=enterprise
```

This makes states bookmarkable.

---

# 59. State Preservation

When user opens a record and returns to a list, preserve:

- selected saved view
- filters
- sort
- page
- scroll position
- columns
- search query

This is a major enterprise UX quality signal.

Do not reset the module every time users navigate back.

---

# 60. Performance Rules

CRM tables can contain thousands or millions of records.

Design frontend assuming large data.

Use:
- server-side pagination/filtering/sorting where appropriate
- debounced search
- request cancellation
- virtualization for very long client-side lists
- optimistic mutations where safe
- cached lookup options
- lazy loading of secondary record sections

Do not download all CRM records to the browser.

---

# 61. AI Agent Design Rules

When an AI coding agent uses this skill:

### Always
- inspect existing design system first
- reuse existing components when good enough
- maintain consistent CRM patterns
- prioritize desktop usability
- make tables dense
- make actions predictable
- preserve state
- include empty/loading/error states
- implement responsive behavior
- keep components reusable

### Never
- redesign the navigation differently on each page
- use giant cards for normal table data
- put every field in its own card
- make every button a bright primary button
- add gradients unless explicitly requested
- add random glass effects
- over-animate
- hide common actions unnecessarily
- use fake data permanently when real APIs exist

---

# 62. Page Implementation Recipe

Whenever building a new CRM page, use this order.

## Step 1 — Determine module purpose

Ask internally:

- What entity does this page manage?
- What actions do users perform most?
- What fields are scanned most?
- What states/statuses matter?
- What related entities matter?

## Step 2 — Choose default view

Usually:
- List for general records
- Kanban for pipeline
- Grid for rapid editing
- Calendar for date-driven activities
- Split for high-volume review

## Step 3 — Build module header

Include:
- title
- saved view
- search
- filters
- primary create action
- view switcher
- overflow

## Step 4 — Build content

Table/board/grid.

## Step 5 — Add record detail

Header + summary + sections + related records + timeline.

## Step 6 — Add states

- loading
- empty
- error
- filtered-empty
- permission denied

## Step 7 — Add responsive behavior

Do not defer responsive design until the end.

---

# 63. Visual Quality Checklist

Before completing any CRM screen, verify:

- [ ] Sidebar follows global hierarchy
- [ ] Active module is obvious
- [ ] Topbar remains compact
- [ ] Global search is easy to reach
- [ ] Primary action is obvious
- [ ] Table density is appropriate
- [ ] Table header is sticky when useful
- [ ] Filters are easy to discover
- [ ] Current saved view is visible
- [ ] Selected-row bulk actions are clear
- [ ] Empty state has next action
- [ ] Loading state matches layout
- [ ] Destructive actions are protected
- [ ] Record details have clear hierarchy
- [ ] Related records are accessible
- [ ] Timeline/history is readable
- [ ] Form sections are logical
- [ ] Mobile/tablet behavior is defined
- [ ] Keyboard focus is visible
- [ ] No unnecessary decorative cards
- [ ] No inconsistent icon system
- [ ] No excessive rounding
- [ ] No giant typography
- [ ] State is preserved during navigation

---

# 64. Fidelity Rule

If explicitly asked:

> "Make it like Zoho CRM"

Interpret this as:

- copy the UX logic
- copy the information architecture principles
- copy the density
- copy familiar enterprise patterns
- copy the sidebar + main-pane mental model
- copy module/list/detail workflow concepts

But:

- do not use the Zoho logo
- do not copy branded illustrations
- do not depend on proprietary assets
- do not claim the product is Zoho
- adapt colors and visual identity to the target product

The result should feel immediately familiar to a Zoho CRM user while still being its own product.

---

# 65. Default CRM Screen Specification

If the user gives only a module name with no extra detail, generate this layout:

```text
APP SHELL
  Sidebar
  Topbar

MODULE HEADER
  Module title
  Saved view dropdown
  Module search
  Filter button
  Sort
  Columns
  View switcher
  Primary create button
  More menu

CONTENT
  Dense responsive table
  Row selection
  Row hover actions
  Pagination

SECONDARY
  Filter drawer
  Record preview drawer
  Create/edit form
  Bulk action bar
  Empty/loading/error states
```

This is the default UI contract.

---

# 66. Reference Notes from Modern Zoho CRM NextGen

Modern Zoho CRM uses concepts that inspired this skill, including:

- unified left navigation
- main content pane
- global search
- quick create
- teamspaces
- list views
- Kanban
- grid
- chart
- timeline
- split view
- customizable Canvas-style views
- advanced filters
- mass actions
- record detail pages
- related lists
- layout customization
- dynamic layout rules

Use these as UX references, not as a requirement to duplicate proprietary visual assets.

---

# 67. Final Instruction to Coding Agent

When this skill is active, every CRM feature must look and behave like part of one coherent
enterprise system.

Before writing new UI, inspect nearby CRM screens and reuse their shell, toolbar, table,
form, drawer, modal, spacing, and interaction patterns.

Optimize for the employee who will use the CRM for hours every day.

The interface should feel:

**fast, dense, predictable, professional, configurable, and operational.**
