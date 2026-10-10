# ADR-0002 — Module structure and boundaries

Status: Proposed

Decision: Modules in `app/Modules/<Name>` (namespace `App\Modules\<Name>`), each with its own
service provider, migrations, routes, policies, views, lang and tests. Public surface =
`Contracts`, `DTOs`, `Events`. An architecture test (Pest `arch()` / custom) fails the build when a
module imports another module's non-public classes or violates the dependency direction in
modules.md.

Rejected: separate Composer packages per module (overhead for current team size); can be
extracted later because boundaries are enforced.
