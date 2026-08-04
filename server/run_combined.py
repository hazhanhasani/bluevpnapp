from __future__ import annotations

import asyncio
import os
import signal
import sys
from pathlib import Path

import uvicorn

APP_DIR = Path("/app")
BOT_DIR = Path("/opt/bluevpn_bot")

for directory in (APP_DIR, BOT_DIR):
    value = str(directory)
    if value not in sys.path:
        sys.path.insert(0, value)

from deploy_bot import build_application

# Import failure is intentionally fatal. In particular, a broken or
# missing persistent database must make Railway reject the deployment
# instead of starting an empty fallback panel.
from server.main import app as web_app


async def main() -> None:
    port = int(os.getenv("PORT", "8000"))
    telegram = build_application()

    config = uvicorn.Config(
        web_app,
        host="0.0.0.0",
        port=port,
        loop="asyncio",
        log_level="info",
    )
    web = uvicorn.Server(config)
    web.install_signal_handlers = lambda: None

    await telegram.initialize()
    await telegram.start()

    if telegram.updater is None:
        raise RuntimeError("Telegram updater is unavailable")

    await telegram.updater.start_polling(
        drop_pending_updates=False
    )

    stop = asyncio.Event()
    loop = asyncio.get_running_loop()

    def shutdown() -> None:
        stop.set()
        web.should_exit = True

    for sig in (signal.SIGINT, signal.SIGTERM):
        try:
            loop.add_signal_handler(sig, shutdown)
        except NotImplementedError:
            pass

    web_task = asyncio.create_task(web.serve())
    stop_task = asyncio.create_task(stop.wait())

    _, pending = await asyncio.wait(
        {web_task, stop_task},
        return_when=asyncio.FIRST_COMPLETED,
    )

    for task in pending:
        task.cancel()

    web.should_exit = True

    if not web_task.done():
        try:
            await web_task
        except asyncio.CancelledError:
            pass

    await telegram.updater.stop()
    await telegram.stop()
    await telegram.shutdown()


if __name__ == "__main__":
    asyncio.run(main())
