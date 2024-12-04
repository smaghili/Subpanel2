import os
from telethon import TelegramClient, events
from telethon.tl.functions.messages import GetBotCallbackAnswerRequest
import re
from datetime import datetime
import asyncio
import sys
import json
import logging
from logging.handlers import RotatingFileHandler
import time
from functools import wraps

# Secure logging configuration
log_file = '/var/www/logs/telegram_service.log'
os.makedirs(os.path.dirname(log_file), exist_ok=True)

handler = RotatingFileHandler(log_file, maxBytes=10*1024*1024, backupCount=5)
formatter = logging.Formatter('%(asctime)s - %(levelname)s - %(message)s')
handler.setFormatter(formatter)

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)
logger.addHandler(handler)

# Rate Limiting settings
RATE_LIMIT = 1  # Minimum interval between requests (seconds)
last_request_time = 0

def rate_limit():
    def decorator(func):
        @wraps(func)
        async def wrapper(*args, **kwargs):
            global last_request_time
            current_time = time.time()
            
            # Check time elapsed since last request
            time_passed = current_time - last_request_time
            if time_passed < RATE_LIMIT:
                wait_time = RATE_LIMIT - time_passed
                await asyncio.sleep(wait_time)
            
            last_request_time = time.time()
            return await func(*args, **kwargs)
        return wrapper
    return decorator

# Read credentials from environment file
def get_credentials():
    try:
        with open('/var/www/config/.env', 'r') as f:
            config = dict(line.strip().split('=') for line in f if line.strip() and not line.startswith('#'))
        return config.get('API_ID'), config.get('API_HASH')
    except Exception as e:
        logger.error("Error reading credentials", exc_info=False)
        return None, None

@rate_limit()
async def send_command(client, bot_id):
    try:
        async with asyncio.timeout(10):  # 10 seconds timeout
            await client.send_message(bot_id, '/services')
            await asyncio.sleep(1)
            return True
    except asyncio.TimeoutError:
        logger.error("Timeout while sending command")
        return False
    except Exception as e:
        logger.error("Error sending command", exc_info=False)
        return False

@rate_limit()
async def get_bot_message(client, bot_id):
    try:
        async with asyncio.timeout(10):
            message = await client.get_messages(bot_id, limit=1)
            return message
    except asyncio.TimeoutError:
        logger.error("Timeout while getting message")
        return None
    except Exception as e:
        logger.error("Error getting message", exc_info=False)
        return None

async def main():
    try:
        if len(sys.argv) <= 1:
            logger.error("Bot ID not provided")
            print(json.dumps({"error": "Bot ID is required"}))
            return

        api_id, api_hash = get_credentials()
        if not api_id or not api_hash:
            print(json.dumps({"error": "Failed to read configuration"}))
            return

        session_file = '/var/www/sessions/telegram_session'
        bot_id = sys.argv[1]

        # Security validation for input
        if not bot_id.isalnum():
            logger.warning("Invalid bot ID format")
            print(json.dumps({"error": "Invalid bot ID format"}))
            return
        
        if os.path.exists(session_file):
            os.chmod(session_file, 0o600)
        
        async with TelegramClient(session_file, api_id, api_hash) as client:
            if not await client.is_user_authorized():
                logger.error("No valid session found")
                print(json.dumps({"error": "No valid session found"}))
                return
                
            try:
                if not await send_command(client, bot_id):
                    print(json.dumps({"error": "Failed to send command"}))
                    return
                
                message = await get_bot_message(client, bot_id)
                if not message or not message[0].reply_markup:
                    print(json.dumps({"error": "Bot menu not available"}))
                    return

                async with asyncio.timeout(10):
                    await message[0].click(0, 0)
                    await asyncio.sleep(1)
                    
                    response = await get_bot_message(client, bot_id)
                    if not response or not response[0].text:
                        print(json.dumps({"error": "No response received from bot"}))
                        return

                    text = response[0].text
                    
                    # Extract and validate information
                    total_volume = re.search(r'📦 حجم سرویس : (\d+(?:\.\d+)?)', text)
                    used_volume = re.search(r'📥 حجم مصرفی سرویس : (\d+(?:\.\d+)?)', text)
                    expiry_date = re.search(r'📆 تاریخ انقضای سرویس : (\d{4}/\d{2}/\d{2})', text)
                    
                    if not all([total_volume, used_volume]):
                        raise ValueError("Volume values not found")
                    
                    result = {
                        'total_volume': float(total_volume.group(1)),
                        'used_volume': float(used_volume.group(1)),
                        'expiry_date': expiry_date.group(1) if expiry_date else ''
                    }
                    
                    if result['total_volume'] < 0 or result['used_volume'] < 0:
                        raise ValueError("Invalid volume values")
                    
                    print(json.dumps(result))

            except asyncio.TimeoutError:
                logger.error("Operation timeout")
                print(json.dumps({"error": "Operation timeout"}))
            except ValueError as ve:
                logger.error(f"Validation error: {str(ve)}")
                print(json.dumps({"error": str(ve)}))
            except Exception as e:
                logger.error("Operation error", exc_info=False)
                print(json.dumps({"error": "Operation failed"}))
    
    except Exception as e:
        logger.error("Critical error", exc_info=False)
        print(json.dumps({"error": "System error"}))

if __name__ == "__main__":
    asyncio.run(main()) 