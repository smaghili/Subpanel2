from telethon import TelegramClient
import asyncio
import os

# تنظیمات اولیه
api_id = "23933986"
api_hash = "f61a82f32627f793c85704c163bf2547"
session_file = 'tel_session'

async def create_session():
    # چک کردن وجود فایل سشن
    if os.path.exists(f"{session_file}.session"):
        print("Session file already exists!")
        return
        
    try:
        client = TelegramClient(session_file, api_id, api_hash)
        await client.start()
        print("Session created successfully!")
        await client.disconnect()
    except Exception as e:
        print(f"Error creating session: {str(e)}")

# اجرای اسکریپت
asyncio.run(create_session()) 