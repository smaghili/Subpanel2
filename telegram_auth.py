from telethon import TelegramClient
import asyncio
import sys
import json
import os

# تنظیمات اولیه
api_id = "23933986"
api_hash = "f61a82f32627f793c85704c163bf2547"
session_file = '/var/www/sessions/telegram_session'
phone_file = '/var/www/sessions/phone.txt'

async def check_auth():
    client = TelegramClient(session_file, api_id, api_hash)
    await client.connect()
    
    is_authorized = await client.is_user_authorized()
    await client.disconnect()
    
    print(json.dumps({
        "status": "authorized" if is_authorized else "unauthorized"
    }))

async def delete_session():
    try:
        if os.path.exists(session_file):
            os.remove(session_file)
        if os.path.exists(phone_file):
            os.remove(phone_file)
        print(json.dumps({"status": "success"}))
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))

async def start_auth(phone):
    # ذخیره شماره تلفن در فایل
    with open(phone_file, 'w') as f:
        f.write(phone)
        
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
    try:
        # خواندن شماره تلفن از فایل
        if not os.path.exists(phone_file):
            print(json.dumps({"status": "error", "message": "Please send phone number first"}))
            return
            
        with open(phone_file, 'r') as f:
            phone = f.read().strip()
            
        client = TelegramClient(session_file, api_id, api_hash)
        await client.connect()
        
        try:
            await client.sign_in(phone, code)
            # حذف فایل شماره تلفن بعد از موفقیت
            if os.path.exists(phone_file):
                os.remove(phone_file)
            print(json.dumps({"status": "success"}))
        except Exception as e:
            if "2FA" in str(e):
                print(json.dumps({"status": "password_needed"}))
            else:
                print(json.dumps({"status": "error", "message": str(e)}))
        
        await client.disconnect()
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))

async def verify_2fa(password):
    client = TelegramClient(session_file, api_id, api_hash)
    await client.connect()
    
    try:
        await client.sign_in(password=password)
        # حذف فایل شماره تلفن در صورت موفقیت
        if os.path.exists(phone_file):
            os.remove(phone_file)
        print(json.dumps({"status": "success"}))
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
    
    await client.disconnect()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"status": "error", "message": "Invalid arguments"}))
        sys.exit(1)
    
    action = sys.argv[1]
    
    if action == "check_auth":
        asyncio.run(check_auth())
    elif action == "delete_session":
        asyncio.run(delete_session())
    elif action == "start":
        if len(sys.argv) < 3:
            print(json.dumps({"status": "error", "message": "Phone number required"}))
            sys.exit(1)
        asyncio.run(start_auth(sys.argv[2]))
    elif action == "verify_code":
        if len(sys.argv) < 3:
            print(json.dumps({"status": "error", "message": "Code required"}))
            sys.exit(1)
        asyncio.run(verify_code(sys.argv[2]))
    elif action == "verify_2fa":
        if len(sys.argv) < 3:
            print(json.dumps({"status": "error", "message": "Password required"}))
            sys.exit(1)
        asyncio.run(verify_2fa(sys.argv[2]))
    else:
        print(json.dumps({"status": "error", "message": "Invalid action"})) 