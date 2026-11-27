"""Restore each reference adapter database via SQLite backup and compare live bytes with MySQL receipts."""
import hashlib
import json
from pathlib import Path
import sqlite3
import sys


def verify(snapshot,source,destination):
    destination.mkdir()
    for old in source.glob("*.sqlite"):
        with sqlite3.connect(old.as_uri()+"?mode=ro",uri=True) as src, sqlite3.connect(destination/old.name) as dst:
            src.backup(dst)
    records=json.loads(snapshot.read_text(encoding="utf-8-sig"))
    actual=[]
    for row in records["nodes"]:
        scope=f"{row['tenant_id']}:{row['project_id']}:{row['production_id']}/{row['node']}"
        file=destination/(hashlib.sha256(scope.encode()).hexdigest()+".sqlite")
        with sqlite3.connect(file.as_uri()+"?mode=ro",uri=True) as db:
            op=row["restore_operation"] if row["verified_at"] else row["operation_key"]
            digest=row["previous_digest"] if row["verified_at"] else row["digest"]
            receipt=json.loads(db.execute("SELECT receipt FROM operations WHERE op=? AND service=?",(op,row["service_id"])).fetchone()[0])
            binary=db.execute("SELECT bundle FROM services WHERE service=?",(row["service_id"],)).fetchone()
            read=hashlib.sha256(binary[0]).hexdigest() if binary else None
            assert read==digest==receipt["digest"]
            actual.append(dict(node=row["node"],service=row["service_id"],operation=op,verified_digest=read))
    assert len(actual)==20
    print(json.dumps(dict(counts=records["counts"],manifests=len(records["manifests"]),approvals=len(records["signatures"]),nodes=actual),indent=2))


if __name__=="__main__":
    verify(*(Path(arg).resolve() for arg in sys.argv[1:]))
