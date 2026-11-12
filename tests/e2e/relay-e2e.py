"""End-to-end test of the relay against a FRESH WordPress Playground (caller + processor simulated).

Start (from the repository root, Git Bash, Playground CLI installed anywhere via 'npm install @wp-playground/cli'):
  MSYS_NO_PATHCONV=1 npx wp-playground-cli server --php=8.4 --wp=7.1 --port=9417 --workers=6 \
    --mount-dir "<repo>/kornsw-ujmwqueue" /wordpress/wp-content/plugins/kornsw-ujmwqueue \
    --mount-dir "<repo>/tests/e2e/helper-plugin" /wordpress/wp-content/plugins/uq-e2e-helper \
    --blueprint=tests/e2e/blueprint.json
Run:   python tests/e2e/relay-e2e.py [http://127.0.0.1:9417/ujmw/]

The blueprint installs two test keys and raises the timeouts, because every request in Playground (PHP as
WebAssembly + SQLite) takes 2-3 seconds; the 2 s dequeue confirmation default would always expire there.
The helper plugin in tests/e2e/helper-plugin (TEST ONLY) lets the script act as administrator (admin AJAX actions) and
exposes the in-process client API over '/?uqtest=...'.
The script expects an empty database (restart Playground before every run). Takes about 6 minutes.
"""
import base64, hashlib, json, random, string, sys, threading, time, urllib.parse, urllib.request, urllib.error

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:9417/ujmw/"
SITE = BASE.rsplit("ujmw/", 1)[0]
CALLER = "uq_testcaller"
PROC = "uq_testprocessor"
results = []

def check(cond, desc, detail=""):
    results.append((cond, desc))
    print(("PASS " if cond else "FAIL ") + desc + ("" if cond else "  -> " + str(detail)))

def salt():
    return "".join(random.choice(string.ascii_letters) for _ in range(6))

def phash(secret, s):
    return base64.urlsafe_b64encode(hashlib.sha256((secret + s).encode()).digest()).decode().rstrip("=")

def req(method, path, body=None, key=None, raw=False, base=None, plainKey=False, headers=None):
    data = None
    if body is not None:
        data = body.encode() if isinstance(body, str) else json.dumps(body).encode()
    r = urllib.request.Request((base or BASE) + path, data=data, method=method)
    r.add_header("Content-Type", "application/json")
    if key:
        r.add_header("Authorization", key if plainKey else "Bearer " + key)
    for name, value in (headers or {}).items():
        r.add_header(name, value)
    try:
        with urllib.request.urlopen(r, timeout=60) as resp:
            text = resp.read().decode()
            return resp.status, (text if raw else json.loads(text))
    except urllib.error.HTTPError as e:
        text = e.read().decode()
        try:
            return e.code, json.loads(text)
        except Exception:
            return e.code, text

def auth(secret):
    s = salt()
    return "pollingSalt=" + s + "&pollingHash=" + phash(secret, s)

def dequeue(flt="*", name="w1"):
    return req("GET", "api/reports/_queue?processor=" + urllib.parse.quote(name) + "&dequeue=" + flt, key=PROC)

def put(lease, body=None, name="w1"):
    return req("PUT", "api/reports/_queue?processor=" + urllib.parse.quote(name) + "&requestId=" + lease["requestId"] + "&" + auth(lease["pollingSecret"]), body=body, key=PROC)

def complete(lease, response, nxt=None):
    return req("POST", "api/reports/_queue?processor=w1&requestId=" + lease["requestId"] + "&" + auth(lease["pollingSecret"]), body={"response": response, "dequeueNext": nxt}, key=PROC)

def release(lease):
    return req("DELETE", "api/reports/_queue?processor=w1&requestId=" + lease["requestId"] + "&" + auth(lease["pollingSecret"]), key=PROC)

def poll(method, a):
    return req("GET", "api/reports/" + method + "?asyncId=" + a["asyncId"] + "&" + auth(a["pollingSecret"]), key=CALLER)

def cancel(method, a, wait=0):
    return req("DELETE", "api/reports/" + method + "?asyncId=" + a["asyncId"] + "&" + auth(a["pollingSecret"]) + "&wait=" + str(wait), key=CALLER)

# --- access and learning
st, b = req("POST", "api/reports/RenderReport?wait=0", {"reportId": 1}, key=CALLER)
check(st == 404, "caller on a route without processor gets 404", (st, b))
st, b = req("GET", "api/reports/_queue?dequeue=*")
check(st == 401, "missing key -> 401", (st, b))
st, b = req("GET", "api/reports/_queue?dequeue=*", key=CALLER)
check(st == 403, "caller key on _queue -> 403", (st, b))
st, b = req("GET", "other/x/_queue?dequeue=*", key=PROC)
check(st == 403, "processor key outside its route pattern -> 403", (st, b))
st, b = dequeue()
check(st == 200 and b["requestId"] is None and b["fault"] is None, "first processor contact learns the queue (empty dequeue)", b)

# --- long-running happy path with callbacks
requestJson = '{"reportId":42,"format":"pdf","empty":{},"amount":1.0,"_":{"tenantId":"t1"}}'
t0 = time.time()
st, b = req("POST", "api/reports/RenderReport?wait=1", requestJson, key=CALLER)
check(st == 200 and b["async"]["execState"] == 0 and b["async"]["pollingSecret"] and b["fault"] is None, "POST ?wait returns queued state with asyncId and secret", b)
check(time.time() - t0 >= 0.9, "POST ?wait=1 waited about one second")
a1 = b["async"]
st, raw = req("GET", "api/reports/_queue?processor=w1&dequeue=RenderReport", key=PROC, raw=True)
check(requestJson in raw, "request JSON is passed through byte-for-byte", raw)
lease = json.loads(raw)
check(lease["method"] == "RenderReport" and lease["requestId"] == a1["asyncId"] and lease["heartbeatSec"] == 3, "dequeue shape", lease)
st, b = put(lease, {"reportProgressInvokes": [{"#": 1, "arg1": "Rendering", "arg2": 3, "arg3": 10}]})
check(b == {"cancellationRequested": False, "fault": None}, "confirming heartbeat with callbacks", b)
st, b = poll("RenderReport", a1)
check(b["async"]["execState"] == 1 and b.get("reportProgressInvokes", [{}])[0].get("arg1") == "Rendering" and "pollingSecret" not in b["async"], "caller poll: running + drained callbacks", b)
st, b = poll("RenderReport", a1)
check("reportProgressInvokes" not in b, "callbacks are drained only once", b)
put(lease, {"reportProgressInvokes": [{"#": 2, "arg1": "Done"}]})
st, b = complete(lease, {"return": "https://x/report.pdf", "fault": None, "_": {"tenantId": "t1"}})
check(b == {"fault": None}, "completion accepted", b)
st, b = poll("RenderReport", a1)
check(b["async"]["execState"] == 2 and b["return"] == "https://x/report.pdf" and b["reportProgressInvokes"][0]["#"] == 2 and b["async"]["cancellable"] is False, "caller gets final response incl. remaining callbacks", b)
st, b = req("GET", "api/reports/RenderReport?asyncId=" + a1["asyncId"] + "&" + auth("wrongsecret"), key=CALLER)
check(b == {"fault": "Wrong asyncId or pollingHash"}, "wrong polling hash", b)

# --- completion within wait (no asyncId)
def processor_quick():
    l = {"requestId": None}
    for _ in range(30):
        st, l = dequeue("Quick")
        if l["requestId"]:
            break
    put(l)
    complete(l, {"return": 7, "fault": None})
th = threading.Thread(target=processor_quick); th.start()
st, b = req("POST", "api/reports/Quick?wait=25", {}, key=CALLER)
th.join()
check(b.get("return") == 7 and b["async"] == {"execState": 2}, "completed within wait: result directly, no asyncId", b)

# --- synchronous call (blocking)
def processor_sync():
    l = {"requestId": None}
    for _ in range(30):
        st, l = dequeue("Ping")
        syncLog.append((round(time.time() - t0, 1), l.get("requestId"), l.get("fault")))
        if l["requestId"]:
            break
    put(l)
    complete(l, {"return": "pong", "fault": None})
syncLog = []
t0 = time.time()
th = threading.Thread(target=processor_sync); th.start()
st, raw = req("POST", "api/reports/Ping", '{"x":1}', key=CALLER, raw=True)
th.join()
check(raw == '{"return":"pong","fault":null}', "synchronous call blocks and returns the raw response", (raw, syncLog))

# --- synchronous timeout (setting 6 s in the blueprint)
t0 = time.time()
st, b = req("POST", "api/reports/NobodyHandlesThis", {}, key=CALLER)
check(b == {"fault": "Queue processing timeout"} and time.time() - t0 >= 24, "synchronous timeout fault", (b, time.time() - t0))

# --- dequeue confirmation missed -> withdrawn, redelivered with priority
st, b = req("POST", "api/reports/Confirm?wait=0", {}, key=CALLER); a2 = b["async"]
st, l1 = dequeue("Confirm")
time.sleep(16.5)
st, b = put(l1)
check(b == {"cancellationRequested": True, "fault": "Withdrawn - dequeue confirmation missed"}, "late confirmation is withdrawn", b)
st, l2 = dequeue("Confirm")
check(l2["requestId"] == a2["asyncId"] and l2["pollingSecret"] != l1["pollingSecret"], "withdrawn entry is redelivered with a new secret", l2)
put(l2)

# --- cancellation of a leased entry
st, b = cancel("Confirm", a2)
check(b["async"]["execState"] == -1 and b["async"]["cancellable"] is False, "cancel of a running entry -> execState -1", b)
st, b = put(l2)
check(b["cancellationRequested"] is True, "heartbeat reports cancellationRequested", b)
st, b = release(l2)
check(b == {"fault": None}, "processor confirms the cancellation", b)
st, b = poll("Confirm", a2)
check(b["async"]["execState"] == -2, "caller sees execState -2", b)

# --- cancellation of a queued entry
st, b = req("POST", "api/reports/Later?wait=0", {}, key=CALLER); a3 = b["async"]
st, b = cancel("Later", a3)
check(b["async"]["execState"] == -2, "queued entry is cancelled immediately", b)

# --- release rule and dequeueNext
st, b = req("POST", "api/reports/Rel?wait=0", {"n": 1}, key=CALLER); a4 = b["async"]
st, b = req("POST", "api/reports/Rel?wait=0", {"n": 2}, key=CALLER); a5 = b["async"]
st, l = dequeue("Rel"); put(l)
check(l["requestId"] == a4["asyncId"], "FIFO order", l)
st, b = release(l)
st, l = dequeue("Rel"); put(l)
check(l["requestId"] == a4["asyncId"], "first release requeues at the head", l)
st, nxt = complete(l, {"return": 1, "fault": None}, "Rel")
check(nxt.get("requestId") == a5["asyncId"] and nxt["fault"] is None, "dequeueNext allocates the next entry", nxt)
put(nxt); complete(nxt, {"return": None, "fault": "business error"})
st, b = poll("Rel", a5)
check(b["async"]["execState"] == 3 and b["fault"] == "business error", "business fault -> execState 3", b)

# --- lease loss (setting 5 s in the blueprint)
st, b = req("POST", "api/reports/Lost?wait=0", {}, key=CALLER); a6 = b["async"]
st, l = dequeue("Lost"); put(l)
time.sleep(26)
st, b = poll("Lost", a6)
check(b["async"]["execState"] == 3 and b["fault"] == "processor lost", "lease loss -> fault 'processor lost'", b)
st, b = complete(l, {"return": 1, "fault": None})
check(b == {"fault": "Lease lost"}, "late completion is rejected", b)

# --- access key without 'Bearer', pretty statistics
st, b = req("GET", "api/reports/_queue?processor=w1&dequeue=Nothing", key=PROC, plainKey=True)
check(st == 200 and b["fault"] is None, "Authorization header without 'Bearer ' prefix is accepted", (st, b))
st, raw = req("GET", "api/reports/_queue", key=PROC, raw=True)
check(st == 200 and "\n    " in raw, "statistics are pretty-printed", raw[:80])

# --- admin actions (AJAX as administrator via the test helper plugin)
st, b = req("GET", "?uqtest=nonce", base=SITE, headers={"X-Uq-Test-Admin": "1"})
NONCE = b["nonce"]

def admin(action, **params):
    params["action"] = action
    params["nonce"] = NONCE
    r = urllib.request.Request(SITE + "wp-admin/admin-ajax.php", data=urllib.parse.urlencode(params).encode(), method="POST")
    r.add_header("X-Uq-Test-Admin", "1")
    with urllib.request.urlopen(r, timeout=60) as resp:
        return json.loads(resp.read().decode())["data"]

def instance_of(snapshot, agent):
    for p in snapshot["processors"]:
        if p["agent"] == agent:
            return p["instances"][-1]
    return None

def endpoint_of(snapshot, route):
    for e in snapshot["endpoints"]:
        if e["route"] == route:
            return e
    return None

snap = admin("kornsw_uq_snapshot")
w1 = instance_of(snap, "w1")
check(w1 is not None and w1["name"].startswith("#") and w1["name"].count("#") == 2 and w1["status"] in ("idle", "busy") and w1["lock"] == "none", "processor w1 listed with synthetic instance '#MM-dd#HH-mm-ss'", snap["processors"])
ep = endpoint_of(snap, "api/reports")
check(ep is not None and "RenderReport" in ep["methods"] and ep["agents"] == ["w1"] and ep["unserved"] is False, "endpoint listed with methods and agents", ep)

# drain lock on the only instance: dequeue answers empty, callers get 'No processor available' immediately
snap = admin("kornsw_uq_processor_lock", id=w1["id"], expected="none")
check(instance_of(snap, "w1")["lock"] == "locked", "drain lock without jobs is shown red", instance_of(snap, "w1"))
st, b = req("POST", "api/reports/Locked?wait=0", {}, key=CALLER)
check(b.get("fault") == "No processor available" and b["async"]["execState"] == 3, "no available processor -> immediate fault", b)
st, b = req("GET", "api/reports/_queue?processor=w2%23node-a&capacity=4&dequeue=*", key=PROC)
check(b["requestId"] is None, "second instance w2#node-a registers (announcing capacity 4)", b)
st, b = req("POST", "api/reports/Drain?wait=0", {}, key=CALLER); aD = b["async"]
st, b = dequeue("*")
check(b["requestId"] is None, "locked instance w1 receives no job", b)
st, lD = dequeue("*", name="w2#node-a")
check(lD["requestId"] == aD["asyncId"], "unlocked instance w2 receives the job", lD)
put(lD, name="w2#node-a")
snap = admin("kornsw_uq_processor_lock", id=w1["id"], expected="locked")
check(instance_of(snap, "w1")["lock"] == "none", "click on red unlocks", instance_of(snap, "w1"))
w2 = instance_of(snap, "w2")
check(w2 is not None and w2["name"] == "#node-a" and w2["active"] == 1 and w2["status"] == "busy", "w2 is busy with one job", w2)
check(w2["capacity"] == -1 and instance_of(snap, "w1")["capacity"] == -1, "a dequeue without '&capacity' resets the capacity to -1", w2)
req("GET", "api/reports/_queue?processor=w2%23node-a&capacity=4&dequeue=Nothing", key=PROC)
snap = admin("kornsw_uq_snapshot")
check(instance_of(snap, "w2")["capacity"] == 4, "announced capacity is listed", instance_of(snap, "w2"))

# CANCEL link: caller gets a fault, processor gets cancellationRequested
snap = admin("kornsw_uq_processor_cancel", id=w2["id"])
st, b = poll("Drain", aD)
check(b.get("fault") == "Cancelled by administrator" and b["async"]["execState"] == 3, "[CANCEL] -> caller fault", b)
st, b = put(lD, name="w2#node-a")
check(b == {"cancellationRequested": True, "fault": None}, "[CANCEL] -> processor gets cancellationRequested", b)
st, b = req("DELETE", "api/reports/_queue?processor=w2%23node-a&requestId=" + lD["requestId"] + "&" + auth(lD["pollingSecret"]), key=PROC)
check(b == {"fault": None}, "processor confirms the administrative cancellation", b)

# drain -> kill with a running job
st, b = req("POST", "api/reports/Kill?wait=0", {}, key=CALLER); aK = b["async"]
st, lK = dequeue("Kill"); put(lK)
snap = admin("kornsw_uq_processor_lock", id=w1["id"], expected="none")
check(instance_of(snap, "w1")["lock"] == "draining", "drain lock with a running job is shown orange", instance_of(snap, "w1"))
snap = admin("kornsw_uq_processor_lock", id=w1["id"], expected="none")
check(instance_of(snap, "w1")["lock"] == "draining", "outdated click (expected state changed) is ignored", instance_of(snap, "w1"))
snap = admin("kornsw_uq_processor_lock", id=w1["id"], expected="draining")
check(instance_of(snap, "w1")["lock"] == "locked", "click on orange kills -> red", instance_of(snap, "w1"))
st, b = poll("Kill", aK)
check(b.get("fault") == "Processor-Lock applied during execution" and b["async"]["execState"] == 3, "kill -> caller fault 'Processor-Lock applied during execution'", b)
st, b = put(lK)
check(b == {"cancellationRequested": True, "fault": None}, "kill -> processor gets cancellationRequested with the next PUT", b)
admin("kornsw_uq_processor_lock", id=w1["id"], expected="locked")

# endpoint lock: processors keep running but get no jobs of this endpoint, callers are rejected
snap = admin("kornsw_uq_endpoint_lock", id=ep["id"], expected="none")
check(endpoint_of(snap, "api/reports")["lock"] == "locked" and endpoint_of(snap, "api/reports")["unserved"] is True, "endpoint lock (red)", endpoint_of(snap, "api/reports"))
st, b = req("POST", "api/reports/EpLocked?wait=0", {}, key=CALLER)
check(b.get("fault") == "No processor available", "locked endpoint rejects calls", b)
snap = admin("kornsw_uq_endpoint_lock", id=ep["id"], expected="locked")
check(endpoint_of(snap, "api/reports")["lock"] == "none", "endpoint unlocked", endpoint_of(snap, "api/reports"))
snap = admin("kornsw_uq_endpoint_monitoring", id=ep["id"], enabled="1")
check(endpoint_of(snap, "api/reports")["monitoring"] is True, "monitoring switch", endpoint_of(snap, "api/reports"))

# --- in-process client API (other plugins, no key, endpoints are learned by clients too)
st, b = req("POST", "?uqtest=invokeLongRunning&route=api/inproc&method=Calc&wait=0", '{"a":1}', base=SITE)
check(b.get("fault") == "No processor available", "in-process call on a new endpoint without processor -> fault", b)
st, b = req("GET", "?uqtest=endpoints", base=SITE)
check(b.get("api/inproc") == ["Calc"], "in-process client learned the endpoint and method", b)
req("GET", "api/inproc/_queue?processor=w1&dequeue=Calc", key=PROC)
st, b = req("POST", "?uqtest=invokeLongRunning&route=api/inproc&method=Calc&wait=0", '{"a":2}', base=SITE)
aI = b["async"]
st, lI = req("GET", "api/inproc/_queue?processor=w1&dequeue=Calc", key=PROC)
check(lI["requestId"] == aI["asyncId"] and lI["request"] == {"a": 2}, "processor receives the in-process call", lI)
req("PUT", "api/inproc/_queue?processor=w1&requestId=" + lI["requestId"] + "&" + auth(lI["pollingSecret"]), key=PROC)
req("POST", "api/inproc/_queue?processor=w1&requestId=" + lI["requestId"] + "&" + auth(lI["pollingSecret"]), body={"response": {"return": 3, "fault": None}}, key=PROC)
st, b = req("GET", "?uqtest=poll&route=api/inproc&method=Calc&asyncId=" + aI["asyncId"] + "&secret=" + aI["pollingSecret"], base=SITE)
check(b.get("return") == 3 and b["async"]["execState"] == 2, "in-process poll returns the result", b)

# --- incoming call queue wait timeout (30 s in the blueprint)
st, b = req("POST", "api/reports/NeverDequeued?wait=0", {}, key=CALLER); aW = b["async"]
time.sleep(32)
st, b = poll("NeverDequeued", aW)
check(b.get("fault") == "No processor available" and b["async"]["execState"] == 3, "queued too long -> 'No processor available'", b)

# --- statistics and endpoint info
st, b = req("GET", "api/reports/_queue", key=PROC)
check(st == 200 and b["statsTotal"]["past10h"] >= 6 and any(k.startswith("w1#") for k in b["processors"]) and "w2#node-a" in b["processors"] and "RenderReport" in b["statsPerMethod"], "queue statistics", b)
st, b = req("GET", "api/reports", key=CALLER)
check(st == 200 and "RenderReport" in b["methods"], "endpoint info lists learned methods", b)

failed = [d for c, d in results if not c]
print("\n%d passed, %d failed" % (len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
