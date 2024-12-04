import os
from telethon import TelegramClient
import asyncio
import logging
from logging.handlers import RotatingFileHandler

# تنظیمات لاگینگ امن
log_file = '/var/www/logs/telegram_session.log'
os.makedirs(os.path.dirname(log_file), exist_ok=True)

handler = RotatingFileHandler(log_file, maxBytes=10*1024*1024, backupCount=5)
formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
handler.setFormatter(formatter)

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)
logger.addHandler(handler)

# خواندن کردنشیال‌ها از فایل محیطی
def get_credentials():
    try:
        with open('/var/www/config/.env', 'r') as f:
            config = dict(line.strip().split('=') for line in f if line.strip() and not line.startswith('#'))
        return config.get('API_ID'), config.get('API_HASH')
    except Exception as e:
        logger.error("Error reading credentials", exc_info=False)
        return None, None

async def create_session():
    try:
        # ساخت دایرکتوری sessions با دسترسی محدود
        session_dir = '/var/www/sessions'
        if not os.path.exists(session_dir):
            os.makedirs(session_dir)
            os.chmod(session_dir, 0o750)  # دسترسی فقط برای مالک و گروه
        
        session_file = os.path.join(session_dir, 'telegram_session')
        
        # بررسی وجود فایل سشن
        if os.path.exists(f"{session_file}.session"):
            logger.info("Session file already exists")
            os.chmod(f"{session_file}.session", 0o600)  # دسترسی فقط برای مالک
            return
        
        # دریافت کردنشیال‌ها
        api_id, api_hash = get_credentials()
        if not api_id or not api_hash:
            logger.error("Failed to read credentials")
            return
        
        # ایجاد کلاینت و سشن جدید
        client = TelegramClient(session_file, api_id, api_hash)
        await client.connect()
        
        if not await client.is_user_authorized():
            logger.info("Please check your terminal to complete authentication")
            await client.start()
        
        # تنظیم دسترسی فایل سشن
        if os.path.exists(f"{session_file}.session"):
            os.chmod(f"{session_file}.session", 0o600)
        
        logger.info("Session created successfully")
        await client.disconnect()
        
    except Exception as e:
        logger.error("Error in session creation", exc_info=False)

if __name__ == "__main__":
    asyncio.run(create_session()) 