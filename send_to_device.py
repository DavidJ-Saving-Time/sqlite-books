mkdir -p /tmp/send_to_device_plugin
cat > /tmp/send_to_device_plugin/__init__.py <<'EOF'
from calibre.customize import Plugin
from calibre.gui2.ui import get_gui

class SendToDevice(Plugin):
    name                = 'SendToDevice'
    description         = 'Send books wirelessly to a connected device'
    supported_platforms = ['windows', 'osx', 'linux']
    author              = 'ChatGPT'
    version             = (1, 0, 0)
    minimum_calibre_version = (5, 0, 0)

    def run(self, paths, *args, **kwargs):
        """
        Entry point for:
        calibre-debug -r SendToDevice -- /path/to/book.epub [...]
        """
        if not paths:
            print("Usage: calibre-debug -r SendToDevice -- /path/to/book.epub [...]")
            return 1

        gui = get_gui()
        if gui is None:
            print("Error: Calibre GUI is not running.")
            return 1

        dm = gui.device_manager
        if not dm.is_device_connected:
            print("Starting wireless device connection...")
            dm.start_plugin('wireless')

        print(f"Sending {len(paths)} book(s) to device...")
        results = dm.send_books(paths)
        print("Send result:", results)
        return 0
EOF

echo "send_to_device_plugin" > /tmp/plugin-import-name.txt
cd /tmp && zip -r SendToDevice.zip send_to_device_plugin plugin-import-name.txt
echo "Plugin created: /tmp/SendToDevice.zip"









