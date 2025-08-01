import socket
import struct
import threading
import time
import os
import re
from flask import Flask, request, jsonify
from flask_cors import CORS
from collections import deque

app = Flask(__name__)
CORS(app)  # Enable CORS for all endpoints

# ======================
# CONFIGURATION
# ======================
CONFIG = {
    "IRC_SERVER": "irc.irchighway.net",
    "IRC_PORT": 6667,
    "NICKNAME": "prinn",
    "CHANNEL": "#ebooks",
    "HTTP_PORT": 5001,
    "DOWNLOAD_DIR": "/srv/http/calibre-nilla/downloads",     # Where to store received files
    "LOG_FILE": "/srv/http/calibre-nilla/ircLog/dcc_log",  # Log file
    "DEBUG_LOG_SIZE": 20             # Number of recent IRC messages to keep
}
# ======================

IRC_SOCKET = None
irc_connected = False
irc_last_messages = deque(maxlen=CONFIG["DEBUG_LOG_SIZE"])


# --- LOGGING ---
def log_event(message):
    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    log_line = f"[{timestamp}] {message}"
    print(log_line)
    with open(CONFIG["LOG_FILE"], "a", encoding="utf-8") as log_file:
        log_file.write(log_line + "\n")


# --- IRC HANDLING ---
def irc_connect():
    global IRC_SOCKET, irc_connected
    IRC_SOCKET = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    log_event(f"Connecting to {CONFIG['IRC_SERVER']}:{CONFIG['IRC_PORT']}...")
    IRC_SOCKET.connect((CONFIG["IRC_SERVER"], CONFIG["IRC_PORT"]))
    log_event("Connected to server, logging in...")

    IRC_SOCKET.sendall(f"NICK {CONFIG['NICKNAME']}\r\n".encode())
    IRC_SOCKET.sendall(f"USER {CONFIG['NICKNAME']} 0 * :Python DCC Receiver\r\n".encode())

    while True:
        data = IRC_SOCKET.recv(4096).decode(errors='ignore')
        for line in data.split("\r\n"):
            if line:
                log_event(f"IRC >> {line}")
                irc_last_messages.append(line)
                if "001" in line:
                    log_event(f"Successfully logged in as {CONFIG['NICKNAME']}.")
                    IRC_SOCKET.sendall(f"JOIN {CONFIG['CHANNEL']}\r\n".encode())
                    log_event(f"Joining {CONFIG['CHANNEL']}...")
                if f"JOIN :{CONFIG['CHANNEL']}" in line and CONFIG['NICKNAME'] in line:
                    log_event(f"Joined {CONFIG['CHANNEL']} successfully!")
                    irc_connected = True
                    threading.Thread(target=irc_listener, daemon=True).start()
                    return


def irc_pinger(data):
    if data.startswith("PING"):
        pong_reply = f"PONG {data.split()[1]}\r\n"
        IRC_SOCKET.sendall(pong_reply.encode())


def irc_listener():
    while True:
        try:
            data = IRC_SOCKET.recv(4096).decode(errors='ignore')
            if not data:
                break
            for line in data.split("\r\n"):
                if line.strip():
                    log_event(f"IRC >> {line}")
                    irc_last_messages.append(line)
                    irc_pinger(line)
                    handle_privmsg(line)
        except Exception as e:
            log_event(f"IRC Listener Error: {e}")
            break


def handle_privmsg(line):
    if "PRIVMSG" in line and "DCC SEND" in line:
        dcc_info = parse_dcc_send(line)
        if dcc_info:
            filename, ip, port, size = dcc_info
            log_event(f"Received DCC SEND offer: {filename} ({size} bytes) from {ip}:{port}")
            threading.Thread(target=receive_dcc_file, args=(ip, port, filename, size), daemon=True).start()


def parse_dcc_send(message):
    """
    Parse CTCP DCC SEND messages (quoted & unquoted filenames).
    """
    try:
        match = re.search(r'DCC SEND\s+(?:"(.+?)"|(\S+))\s+(\d+)\s+(\d+)\s+(\d+)', message)
        if not match:
            log_event(f"Failed to match DCC SEND in message: {message}")
            return None

        filename = match.group(1) if match.group(1) else match.group(2)
        ip_int = int(match.group(3))
        port = int(match.group(4))
        size = int(match.group(5))

        ip = socket.inet_ntoa(struct.pack('!I', ip_int))
        return filename, ip, port, size
    except Exception as e:
        log_event(f"Failed to parse DCC SEND: {e} | Message: {message}")
        return None


def receive_dcc_file(ip, port, filename, filesize):
    """Accept DCC connection and download file with .incomplete flag."""
    os.makedirs(CONFIG["DOWNLOAD_DIR"], exist_ok=True)
    final_path = os.path.join(CONFIG["DOWNLOAD_DIR"], filename)
    temp_path = final_path + ".incomplete"

    try:
        log_event(f"Connecting to {ip}:{port} to receive {filename} ({filesize} bytes)...")
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect((ip, port))

        bytes_received = 0
        with open(temp_path, "wb") as f:
            while bytes_received < filesize:
                data = s.recv(4096)
                if not data:
                    break
                f.write(data)
                bytes_received += len(data)
                ack = struct.pack("!I", bytes_received)
                s.send(ack)

        s.close()

        if bytes_received < filesize:
            log_event(f"⚠️ Incomplete download: {filename} received {bytes_received} / {filesize} bytes")
        else:
            os.rename(temp_path, final_path)
            log_event(f"✅ File {filename} received successfully ({bytes_received} bytes)")

    except Exception as e:
        log_event(f"❌ Error receiving file {filename}: {e}")


# --- HTTP API ---
@app.route("/status", methods=["GET"])
def api_status():
    return jsonify({
        "connected": irc_connected,
        "server": CONFIG["IRC_SERVER"],
        "channel": CONFIG["CHANNEL"],
        "last_messages": list(irc_last_messages)
    })


@app.route("/downloaded-files", methods=["GET"])
def api_downloaded_files():
    os.makedirs(CONFIG["DOWNLOAD_DIR"], exist_ok=True)
    files = []
    for fname in os.listdir(CONFIG["DOWNLOAD_DIR"]):
        fpath = os.path.join(CONFIG["DOWNLOAD_DIR"], fname)
        if os.path.isfile(fpath):
            stat = os.stat(fpath)
            files.append({
                "name": fname,
                "size": stat.st_size,
                "modified": time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(stat.st_mtime))
            })
    return jsonify(files)


@app.route("/request-file", methods=["GET"])
def api_request_file():
    cmd = request.args.get("cmd")
    if cmd:
        IRC_SOCKET.sendall(f"PRIVMSG {CONFIG['CHANNEL']} :{cmd}\r\n".encode())
        log_event(f"Sent request command: {cmd}")
        return jsonify({"status": f"Request command '{cmd}' sent"}), 200
    return jsonify({"error": "No 'cmd' parameter provided"}), 400


@app.route("/send-message", methods=["POST"])
def api_send_message():
    msg = request.json.get("msg", "")
    if msg:
        IRC_SOCKET.sendall(f"PRIVMSG {CONFIG['CHANNEL']} :{msg}\r\n".encode())
        log_event(f"Sent message: {msg}")
        return jsonify({"status": "Message sent"}), 200
    return jsonify({"error": "No message provided"}), 400

@app.route("/logs", methods=["GET"])
def api_logs():
    """Return the last 50 lines of the DCC log file."""
    try:
        if not os.path.exists(CONFIG["LOG_FILE"]):
            return jsonify(["Log file not found."])
        with open(CONFIG["LOG_FILE"], "r", encoding="utf-8") as log_file:
            lines = log_file.readlines()
            return jsonify(lines[-50:])  # Return last 50 lines
    except Exception as e:
        return jsonify([f"Error reading log file: {e}"])

    
def start_flask():
    app.run(host="0.0.0.0", port=CONFIG["HTTP_PORT"])


if __name__ == "__main__":
    irc_connect()
    start_flask()

