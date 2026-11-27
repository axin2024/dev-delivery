"""Self-hosted release-train node. Each environment/node owns a transactional SQLite store."""
import argparse
import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import socket
from datetime import datetime, timezone
import sqlite3
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


def fields(bundle, name):
    values = bundle.get(name, [])
    if not isinstance(values,list) or len(values)>100 or any(not isinstance(v,str) or not re.fullmatch(r"[a-z][a-z0-9_]{0,39}",v) for v in values):
        raise ValueError(name)
    return values


def inspect(db, bundle):
    columns = {r[1] for r in db.execute("PRAGMA table_info(app_records)")}
    missing = sorted(set(fields(bundle,"requires")) - columns)
    forbidden = sorted(set(fields(bundle,"forbids")) & columns)
    return {"passed":not missing and not forbidden, "missing":missing, "incompatible":forbidden}


def execute(db, command, data):
    service = data["service"]
    if not isinstance(service,int) or service<1:
        raise ValueError("service")
    current = db.execute("SELECT digest FROM services WHERE service=?",(service,)).fetchone()
    actual = current[0] if current else None
    latest=db.execute("SELECT op FROM operations WHERE service=? ORDER BY rowid DESC LIMIT 1",(service,)).fetchone()
    last_op=latest[0] if latest else None
    if command=="current":
        return {"digest":actual,"operation":last_op}
    key = data["operation"]
    if not isinstance(key,str) or not re.fullmatch(r"[0-9a-f]{32}",key):
        raise ValueError("operation")
    prior = db.execute("SELECT service,digest,kind,receipt FROM operations WHERE op=?",(key,)).fetchone()
    if command=="result":
        if prior and prior[0]!=service:
            raise ValueError("operation_scope")
        return {"found":bool(prior),"receipt":json.loads(prior[3]) if prior else None}
    if command not in ("apply","restore","inspect"):
        raise ValueError("command")
    digest = data["digest"]
    remove=digest is None and command in ("restore","inspect")
    if not remove and (not isinstance(digest,str) or not re.fullmatch(r"[a-f0-9]{64}",digest)):
        raise ValueError("digest")
    if prior and command!="inspect":
        if (service,digest or "",command)!=prior[:3]:
            raise ValueError("operation_conflict")
        return json.loads(prior[3])
    if remove:
        raw=None
    elif command=="apply":
        raw=base64.b64decode(data["content_base64"],validate=True)
    else:
        archived=db.execute("SELECT content FROM bundles WHERE digest=?",(digest,)).fetchone()
        if not archived:
            raise ValueError("previous_bundle_unavailable")
        raw=archived[0]
    if raw is not None and hashlib.sha256(raw).hexdigest()!=digest:
        raise ValueError("digest")
    bundle=json.loads(raw) if raw is not None else {"protocol":1}
    if not isinstance(bundle,dict) or bundle.get("protocol")!=1:
        raise ValueError("bundle_protocol")
    changes=fields(bundle,"add_fields")
    probe=inspect(db,bundle)
    if command=="inspect":
        return {**probe,"actual_digest":actual,"target_digest":digest,"actual_operation":last_op}
    if data.get("expected_digest")!=actual:
        raise ValueError("node_digest_changed")
    if command=="restore" and not probe["passed"]:
        raise ValueError("incompatible_restore")
    if command=="restore" and last_op!=data["expected_operation"]:
        raise ValueError("node_operation_changed")
    added=[]
    if command=="apply":
        columns={r[1] for r in db.execute("PRAGMA table_info(app_records)")}
        for name in changes:
            if name not in columns:
                db.execute('ALTER TABLE app_records ADD COLUMN "'+name+'" TEXT')
                columns.add(name)
                added.append(name)
    db.execute("INSERT OR IGNORE INTO bundles SELECT digest,bundle FROM services WHERE service=?",(service,))
    if remove:
        db.execute("DELETE FROM services WHERE service=?",(service,))
    else:
        db.execute("INSERT OR IGNORE INTO bundles VALUES (?,?)",(digest,raw))
        db.execute("INSERT INTO services VALUES (?,?,?) ON CONFLICT(service) DO UPDATE SET digest=excluded.digest,bundle=excluded.bundle",(service,digest,raw))
    probe=inspect(db,bundle)
    blob=db.execute("SELECT bundle FROM services WHERE service=?",(service,)).fetchone()
    count=db.execute("SELECT count(*) FROM operations WHERE service=?",(service,)).fetchone()[0]+1
    result={"operation":key,"service":service,"node":data["node"],"previous_digest":actual,"digest":hashlib.sha256(blob[0]).hexdigest() if blob else None,
            "migration":{"added":added},"probe":probe,"effect_count":count,
            "applied_at":datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S.%f")}
    db.execute("INSERT INTO operations VALUES (?,?,?,?,?)",(key,service,digest or "",command,json.dumps(result)))
    return result


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if not hmac.compare_digest(self.headers.get("Authorization",""), "Bearer "+self.server.secret):
            return self.reply(403,{"error":"access_denied"})
        try:
            size=int(self.headers.get("Content-Length","0"))
            if not 0<size<=12*1024*1024:
                return self.reply(413,{"error":"size"})
            data=json.loads(self.rfile.read(size))
            scope=data["scope"]
            node=data["node"]
            if not isinstance(scope,str) or not re.fullmatch(r"[1-9]\d*:[1-9]\d*:[1-9]\d*",scope) or not isinstance(node,str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,63}",node):
                raise ValueError("target")
            filename=hashlib.sha256((scope+"/"+node).encode()).hexdigest()+".sqlite"
            db=sqlite3.connect(self.server.root/filename,timeout=8,isolation_level=None)
            command=self.path.removeprefix("/")
            try:
                db.execute("BEGIN IMMEDIATE")
                db.execute("CREATE TABLE IF NOT EXISTS app_records (id INTEGER PRIMARY KEY)")
                db.execute("CREATE TABLE IF NOT EXISTS services (service INTEGER PRIMARY KEY,digest TEXT NOT NULL,bundle BLOB NOT NULL)")
                db.execute("CREATE TABLE IF NOT EXISTS bundles (digest TEXT PRIMARY KEY,content BLOB NOT NULL)")
                db.execute("CREATE TABLE IF NOT EXISTS operations (op TEXT PRIMARY KEY,service INTEGER NOT NULL,digest TEXT NOT NULL,kind TEXT NOT NULL,receipt TEXT NOT NULL)")
                result=execute(db,command,data)
                drop=False
                if command=="apply" and self.server.drop_node==node:
                    db.execute("CREATE TABLE IF NOT EXISTS fault_drops (label TEXT PRIMARY KEY)")
                    drop=db.execute("INSERT OR IGNORE INTO fault_drops VALUES ('first_apply')").rowcount==1
                db.commit()
            finally:
                db.close()
            if drop:
                self.close_connection=True
                self.connection.shutdown(socket.SHUT_RDWR)
                return
            self.reply(200,result)
        except (ValueError,KeyError,TypeError,sqlite3.Error) as error:
            self.reply(422,{"error":str(error)[:120]})

    def reply(self,status,body):
        raw=json.dumps(body,separators=(",",":")).encode()
        self.send_response(status)
        self.send_header("Content-Type","application/json")
        self.send_header("Content-Length",str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)


if __name__=="__main__":
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port",type=int,default=8098)
    parser.add_argument("--data",type=Path,required=True)
    parser.add_argument("--drop-first-response-on",default="")
    args=parser.parse_args()
    token=os.environ.get("DELIVERY_TRAIN_ADAPTER_TOKEN","")
    if len(token)<32:
        parser.error("DELIVERY_TRAIN_ADAPTER_TOKEN needs at least 32 characters")
    args.data.mkdir(parents=True,exist_ok=True)
    server=ThreadingHTTPServer(("127.0.0.1",args.port),Handler)
    server.secret=token
    server.root=args.data
    server.drop_node=args.drop_first_response_on
    server.serve_forever()
