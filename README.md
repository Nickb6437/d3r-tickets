# tickets

Everything you have in flight in one table: assigned Jira tickets, the branches and pull
requests behind them, and the staging site each is deployed to.

```
LIVE (2)
LF-7873* Frontend Buildout          LF-7873-basket-updates      #7301 open behind approved  damson
         └ [LF-7873] Add returns…   LF-7956_FEATURE-returns-…   #7334 merged
         └ [LF-7716] Hookup New…    LF-7873-basket-updates-NB   #7312 merged
```

Ticket key opens Jira. Summary and `#number` open the pull request. Alias opens staging.

## Commands

| Command | Shows |
|---|---|
| `tickets --active` | **The everyday view.** Still in flight, Live hidden |
| `tickets` | Last 90 days, every status |
| `tickets --since 21` | Last 21 days (`--since 0` for no limit) |
| `tickets LF-8141` | One ticket, whoever it belongs to, any age |
| `tickets basket` | Partial match on summary, branch or repo |
| `tickets -p LF` | One project |
| `tickets -s review` | Status, partial, so this catches D3R and LOAF review |
| `tickets -m niki` | Your work on a staging machine |
| `tickets -m niki -a` | **Every box on a machine**, whoever owns it, including ones on trunk. Leave `--active` off for the complete list |
| `tickets doctor` | Check Jira, gh and stoker are set up and reachable |

Flags combine: `tickets -p LF -s review -m niki`.

Machines: **niki** loaf, **aliz** soho, **anya** general.

Also: `--fresh` (skip the cached deployment list), `--no-links`, `--no-colour`, `-h`.

## Reading it

- **`*` after a key** means the ticket is not assigned to you. Normal: the ticket branch
  someone else owns is what gets deployed, while your branch merges into it.
- **One row per ticket.** The lead line is the pull request on that row's branch; the rest
  of the ticket's pull requests follow beneath, ranked open, merged, closed.
- **Flags on open pull requests**: `conflict`, `behind`, `approved`, `changes`. Approved
  and behind show together, since that is the one worth acting on.
- **A repo prefix** (`cloudinary:`) marks a branch outside the repo most of the list is in.

## Setup

```sh
ln -s ~/Documents/GitHub/d3r-tickets/bin/tickets ~/.local/bin/tickets
cp config.ini.dist ~/.config/d3r-tickets/config.ini
chmod 600 ~/.config/d3r-tickets/config.ini
tickets doctor
```

Add a Jira API token from
[id.atlassian.com](https://id.atlassian.com/manage-profile/security/api-tokens).
**Quote it in the ini file**, it contains an `=` and will not parse bare.

`gh` supplies its own auth. Stoker needs the stack box up and `ssh stack` working.

## How it joins up

The branch is the join key, not the ticket, so vendor and core work with no ticket still
appears, grouped under **No ticket**.

| Source | Gives |
|---|---|
| Jira JQL | assigned tickets and status |
| Jira dev panel | every branch and pull request on a ticket, whoever opened it |
| GitHub via `gh` | pull request state, mergeability, review decision |
| Stoker over ssh | staging alias, URL, machine |

Nothing is configured per client. Handles come from `stoker deployment:ls --local`, each
repo from the newest local checkout's git remote (which is how `thesleeproom` resolves to
`D3R/loaf`), and staging URLs compose as `<alias>-<handle>-<machine>.d3r.com`.

Where a ticket branch and a personal `-XX` branch both exist, staging falls back to the
ticket key, so `LF-7873-basket-updates-NB` still finds `damson` running the ticket branch.

Each source fails on its own: a halted stack box costs you the staging column and one
warning, not an error. Only a broken config or a rejected Jira token exits non-zero.

## Worth knowing

- Jira returns one page of 100 tickets and warns when it hits that. `--active` or a
  shorter `--since` keeps you under it.
- Deployments are cached for two minutes in `~/.cache/d3r-tickets/`. Use `--fresh` after
  redeploying a box.
- Ticket keys come from the branch name first, then the pull request title, since not
  every branch carries one (`bugherd-fixes` only names LF-7978 in its title). A key only
  counts if Jira recognises it, which is what stops `revert-7364-…` reading as REVERT-7364.
- `--active` hides finished tickets by status, and rows with no ticket by their pull
  request: merged or closed with nothing deployed. A staging box is never hidden this way.
- Runs in 8 to 10 seconds, with a spinner on stderr naming the current step. Stdout stays
  clean, so piping and `--no-colour` are unaffected.

## Layout

| File | Job |
|---|---|
| `bin/tickets` | Arguments and output, nothing else |
| `src/Board.php` | Orchestration: fetch, assemble rows, collapse per ticket |
| `src/Jira.php` | Tickets and the development panel |
| `src/GitHub.php` | Pull requests, via `gh` GraphQL |
| `src/Stoker.php` | Staging deployments and the local client map, over ssh |
| `src/StagingMatcher.php` | Which box belongs to which row |
| `src/PullRequests.php` | Resolving a row's pull requests and picking the lead |
| `src/Filters.php` | The filter grammar, JQL and in memory |
| `src/Table.php` | Rendering |
| `src/Ansi.php` | Colour and hyperlinks |
| `src/Doctor.php` | The setup checks |
| `src/Key.php` | Ticket keys in branch names |
| `src/Progress.php` | The spinner, on stderr |
| `src/Process.php` | Background commands, so gh and ssh overlap |
