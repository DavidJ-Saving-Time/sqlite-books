from flask import Flask, jsonify
from flask_cors import CORS
import subprocess
import os
import signal


app = Flask(__name__)
CORS(app)

DAEMON_PATH = "/srv/http/calibre-nilla/irc_dcc_daemon.py"  # <--- Change this
PID_FILE = "/tmp/irc_dcc_daemon.pid"

@app.route("/start", methods=["POST"])
def start_daemon():
    if os.path.exists(PID_FILE):
        return jsonify({"status": "Already running"}), 200

    proc = subprocess.Popen(
        ["python3", DAEMON_PATH],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    with open(PID_FILE, "w") as f:
        f.write(str(proc.pid))
    return jsonify({"status": "Started"}), 200

@app.route("/stop", methods=["POST"])
def stop_daemon():
    if not os.path.exists(PID_FILE):
        return jsonify({"status": "Not running"}), 200

    with open(PID_FILE) as f:
        pid = int(f.read())
    try:
        os.kill(pid, signal.SIGTERM)
        os.remove(PID_FILE)
        return jsonify({"status": "Stopped"}), 200
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route("/status", methods=["GET"])
def status():
    running = os.path.exists(PID_FILE)
    return jsonify({"running": running})

if __name__ == "__main__":
    app.run(port=5050)

