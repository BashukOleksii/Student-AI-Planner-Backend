# Stage 01 documentation placement

Recommended layout inside the **backend Laravel repository**:

```text
backend-repository/
├── AGENTS.md                         # existing; keep as the primary Codex instruction file
├── README.md
├── docs/
│   ├── architecture.md
│   ├── domain-model.md
│   ├── business-rules.md
│   ├── database-review-notes.md
│   └── decisions/
│       └── ADR-001-backend-architecture.md
└── codex/
    └── stage-01-architecture-task.md
```

## How to apply this package

1. Copy the `docs/` directory into the root of the backend repository.
2. Copy the `codex/` directory into the root of the backend repository.
3. Do **not** replace the existing `AGENTS.md` blindly. Open `AGENTS-stage-01-snippet.md` and merge the relevant section into the existing `AGENTS.md`.
4. Commit documentation separately from future database migrations or application code.
5. Do not modify the existing database schema during Stage 01. The physical MySQL design belongs to Stage 02.

## Suggested commit

```text
docs: define backend architecture and domain rules
```
