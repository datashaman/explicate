# Artifact Filesystem Migration Plan

## Goal
Move the project’s artifact layer from a database-backed tree to the actual workspace filesystem on disk.

Artifacts should be represented by real files and folders, not rows in a `workspace_files` table. The database may still hold metadata, but the filesystem must be the source of truth.

## Why this change
- The current artifact model is database-based.
- The team plans to work with Git repositories soon.
- A real filesystem is required for Git-friendly workflows and for keeping artifacts portable outside the application database.

## Current shape to replace
- There is a `WorkspaceFile` model backed by a `workspace_files` table.
- Files and folders are stored as rows with `type`, `name`, `path`, `parent_id`, and optional `content`.
- UI, MCP tools, and agent tools currently read and write through that model.

## Target shape
- Each workspace has a root directory on disk.
- Folders and files exist as real paths under that root.
- The app reads directory structure from disk.
- The app writes to disk directly.
- Any database record should become optional metadata or indexing, not the source of truth.

## Constraints
- Keep the current user experience intact while the backend changes.
- Preserve dashboard links to files.
- Preserve agent replies that point to files.
- Keep the migration safe for existing artifacts.
- Do not assume a single flat directory; nested folders must work.
- The implementation should be compatible with future Git repo support.

## Recommended implementation plan

### 1. Define a workspace filesystem root
- Pick a deterministic root path for each workspace.
- Example pattern: `storage/app/workspaces/{workspace-slug}/`.
- Ensure the path is isolated per workspace.
- Add helper methods for resolving:
  - workspace root
  - relative artifact path
  - absolute filesystem path

### 2. Introduce a filesystem service
- Create a service class responsible for:
  - listing files and folders
  - reading file contents
  - writing files
  - creating folders
  - deleting files and folders
  - renaming/moving paths
- The service should hide all path normalization and safety checks.
- Reject path traversal and invalid names.

### 3. Update the artifact UI to read from disk
- Replace `WorkspaceFile` tree reads with filesystem directory traversal.
- Render files and folders from actual directory entries.
- Preserve clickable links to file views.
- Preserve the current editor behavior when opening a file.

### 4. Update MCP file tools
- Rework `list-files`, `get-file`, `write-file`, and `delete-file` to use the filesystem service.
- Keep payload shape stable where possible.
- Maintain `dashboard_url` generation.
- Ensure folder creation remains automatic for nested file writes.

### 5. Update agent tool access
- Keep agent access to files, but point it at the filesystem service.
- Maintain the current instruction that agents should prefer artifacts for long or structured output.
- Ensure files written by agents are real files on disk.

### 6. Migrate existing artifact data
- Write a one-time migration script or command that:
  - creates workspace directories
  - recreates folders on disk
  - writes existing file contents to disk
  - preserves relative paths
- Decide whether to keep the database rows temporarily as an index or remove them after migration.

### 7. Decide the future of the `workspace_files` table
One of these must be chosen:
- keep it as metadata only
- repurpose it as an index
- deprecate it after the migration

The implementation should make that choice explicit rather than leaving the table half-used.

### 8. Add regression tests
Test at minimum:
- listing files and folders from disk
- writing a nested file creates parent folders
- reading file content returns the disk content
- deleting a file removes it from the listing
- file links still resolve from the dashboard
- agent writes create real files

### 9. Verify Git readiness
- Make sure the artifact root can later become a Git repository root or a nested repo root.
- Avoid storing app-specific path assumptions in file contents.
- Keep metadata separate from the content tree.

## Risks
- Existing UI and tools may assume a DB row exists for every file.
- Path normalization bugs can cause files to be written outside the workspace root.
- Migration may need to reconcile duplicate names or invalid legacy paths.
- Folder/file deletion semantics may differ once disk is the source of truth.

## Suggested sequence for implementation
1. Implement the filesystem service.
2. Switch read paths first.
3. Switch write/delete paths next.
4. Add migration for existing artifacts.
5. Remove or demote database dependence.
6. Run the relevant tests and browser checks.

