#!/usr/bin/env python3
"""Переносит в vacancy_trash «мёртвые» оболочки: без assessment и почти без description."""

from __future__ import annotations

import os
import subprocess
import sys

PGHOST = os.environ.get("LIVE_PGHOST", "172.20.0.8")
PGPORT = os.environ.get("LIVE_PGPORT", "5432")
PGUSER = os.environ.get("LIVE_PGUSER", "job_search")
PGPASSWORD = os.environ.get("LIVE_PGPASSWORD", "job_search")
PGDATABASE = os.environ.get("LIVE_PGDATABASE", "job_search")

REASON = "empty_unassessed_shell"


def run_psql(sql: str) -> str:
    env = os.environ.copy()
    env["PGPASSWORD"] = PGPASSWORD
    proc = subprocess.run(
        [
            "psql",
            "-h",
            PGHOST,
            "-p",
            PGPORT,
            "-U",
            PGUSER,
            "-d",
            PGDATABASE,
            "-v",
            "ON_ERROR_STOP=1",
            "-At",
            "-c",
            sql,
        ],
        env=env,
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or proc.stdout.strip() or "psql failed")
    return proc.stdout.strip()


def main() -> int:
    run_psql(
        """
CREATE TABLE IF NOT EXISTS vacancy_trash (
  vacancy_id uuid PRIMARY KEY,
  external_id text NOT NULL,
  title text,
  company_id uuid,
  created_at timestamptz,
  description_len int NOT NULL DEFAULT 0,
  source_status text,
  reason text NOT NULL,
  trashed_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS vacancy_trash_external_id_idx ON vacancy_trash (external_id);
CREATE INDEX IF NOT EXISTS vacancy_trash_trashed_at_idx ON vacancy_trash (trashed_at DESC);
"""
    )

    moved = run_psql(
        f"""
WITH dead AS (
  SELECT v.id, v.external_id, v.title, v.company_id, v.created_at,
         length(coalesce(v.description, '')) AS description_len,
         v.source_status
  FROM vacancies v
  WHERE NOT EXISTS (SELECT 1 FROM assessments a WHERE a.vacancy_id = v.id)
    AND length(coalesce(v.description, '')) < 50
    AND NOT EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id)
)
INSERT INTO vacancy_trash (
  vacancy_id, external_id, title, company_id, created_at,
  description_len, source_status, reason
)
SELECT id, external_id, title, company_id, created_at,
       description_len, source_status, '{REASON}'
FROM dead
RETURNING 1
"""
    )
    # psql -At with RETURNING prints one line per row
    n = len([ln for ln in moved.splitlines() if ln.strip()]) if moved else 0

    run_psql(
        f"""
UPDATE vacancies v
SET archived = true,
    source_status = 'trash',
    source_status_reason = '{REASON}',
    source_status_checked_at = now(),
    updated_at = now()
WHERE EXISTS (SELECT 1 FROM vacancy_trash t WHERE t.vacancy_id = v.id AND t.reason = '{REASON}')
  AND coalesce(v.source_status, '') <> 'trash';
"""
    )

    total = run_psql("SELECT count(*) FROM vacancy_trash")
    print(f"moved={n} trash_total={total}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as e:
        print(f"error: {e}", file=sys.stderr)
        raise SystemExit(1)
