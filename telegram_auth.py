from telethon import TelegramClient
import asyncio
import sys
import json
import os

# تنظیمات اولیه
api_id = "23933986"
api_hash = "f61a82f32627f793c85704c163bf2547"
session_file = '/var/www/sessions/telegram_session'

async def start_auth(phone):
    client = TelegramClient(session_file, api_id, api_hash)
    await client.connect()
    
    if not await client.is_user_authorized():
        try:
            await client.send_code_request(phone)
            print(json.dumps({"status": "code_needed"}))
        except Exception as e:
            print(json.dumps({"status": "error", "message": str(e)}))
    else:
        print(json.dumps({"status": "already_authorized"}))
    
    await client.disconnect()

async def verify_code(code):
    client = TelegramClient(session_file, api_id, api_hash)
    await client.connect()
    
    try:
        await client.sign_in(code=code)
        print(json.dumps({"status": "success"}))
    except Exception as e:
        if "2FA" in str(e):
            print(json.dumps({"status": "password_needed"}))
        else:
            print(json.dumps({"status": "error", "message": str(e)}))
    
    await client.disconnect()

async def verify_2fa(password):
    client = TelegramClient(session_file, api_id, api_hash)
    await client.connect()
    
    try:
        await client.sign_in(password=password)
        print(json.dumps({"status": "success"}))
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
    
    await client.disconnect()

if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({"status": "error", "message": "Invalid arguments"}))
        sys.exit(1)
    
    action = sys.argv[1]
    value = sys.argv[2]
    
    if action == "start":
        asyncio.run(start_auth(value))
    elif action == "verify_code":
        asyncio.run(verify_code(value))
    elif action == "verify_2fa":
        asyncio.run(verify_2fa(value))
    else:
        print(json.dumps({"status": "error", "message": "Invalid action"})) 