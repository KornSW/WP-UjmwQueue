"""End-to-end test of the relay against a FRESH WordPress Playground (caller + processor simulated).

Start (from the repository root, Git Bash, Playground CLI installed anywhere via 'npm install @wp-playground/cli'):
  MSYS_NO_PATHCONV=1 npx wp-playground-cli server --php=8.4 --wp=7.1 --port=9417 --workers=6     --mount-dir "<repo>/kornsw-ujmwqueue" /wordpress/wp-content/plugins/kornsw-ujmwqueue     --blueprint=tests/e2e/blueprint.json
Run:   python tests/e2e/relay-e2e.py [http://127.0.0.1:9417/ujmw/]

The blueprint installs two test keys and raises the timeouts, because every request in Playground (PHP as
WebAssembly + SQLite) takes 2-3 seconds; the 2 s dequeue confirmation default would always expire there.
The script expects an empty database (restart Playground before every run). Takes about 4 minutes.
"""
import base64, hashlib, json, random, string, sys, threading, time, urllib.request, urllib.error

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:9417/ujmw/"
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

def req(method, path, body=None, key=None, raw=False):
    data = None
    if body is not None:
        data = body.encode() if isinstance(body, str) else json.dumps(body).encode()
    r = urllib.request.Request(BASE + path, data=data, method=method)
    r.add_header("Content-Type", "application/json")
    if key:
        r.add_header("Authorization", "Bearer " + key)
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
    return req("GET", "api/reports/_queue?processor=" + name + "&dequeue=" + flt, key=PROC)

def put(lease, body=None, name="w1"):
    return req("PUT", "api/reports/_queue?processor=" + name + "&requestId=" + lease["requestId"] + "&" + auth(lease["pollingSecret"]), body=body, key=PROC)

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
    time.sleep(0.2)
    st, l = dequeue("Quick")
    put(l)
    complete(l, {"return": 7, "fault": None})
th = threading.Thread(target=processor_quick); th.start()
st, b = req("POST", "api/reports/Quick?wait=25", {}, key=CALLER)
th.join()
check(b.get("return") == 7 and b["async"] == {"execState": 2}, "completed within wait: result directly, no asyncId", b)

# --- synchronous call (blocking)
def processor_sync():
    time.sleep(0.2)
    st, l = dequeue("Ping")
    put(l)
    complete(l, {"return": "pong", "fault": None})
th = threading.Thread(target=processor_sync); th.start()
st, raw = req("POST", "api/reports/Ping", '{"x":1}', key=CALLER, raw=True)
th.join()
check(raw == '{"return":"pong","fault":null}', "synchronous call blocks and returns the raw response", raw)

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

# --- statistics and endpoint info
st, b = req("GET", "api/reports/_queue", key=PROC)
check(st == 200 and b["statsTotal"]["past10h"] >= 6 and "w1" in b["processors"] and "RenderReport" in b["statsPerMethod"], "queue statistics", b)
st, b = req("GET", "api/reports", key=CALLER)
check(st == 200 and "RenderReport" in b["methods"], "endpoint info lists learned methods", b)

failed = [d for c, d in results if not c]
print("\n%d passed, %d failed" % (len(results) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
