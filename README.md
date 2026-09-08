# Free Fire UID Info — GitHub Pages Edition

এই ZIP-এর `index.html` হলো GitHub Pages-এর জন্য static website। **PHP GitHub Pages-এ run হয় না**, তাই `bot_fixed.php`-কে Pages-এ backend হিসেবে ব্যবহার করা যাবে না।

## GitHub Pages-এ চালানোর নিয়ম

1. GitHub-এ একটি নতুন repository বানান।
2. ZIP extract করে `index.html` এবং `assets/` folder repository-এর root-এ upload করুন।
3. **Settings → Pages** থেকে `Deploy from a branch` নির্বাচন করুন।
4. Branch হিসেবে `main` এবং folder হিসেবে `/ (root)` দিন।
5. কিছুক্ষণ পরে Pages URL-এ website খুলবে।
6. Website-এর ⚙️ Settings-এ API URL ও API key দিন।

## গুরুত্বপূর্ণ নিরাপত্তা বিষয়

GitHub Pages public static hosting। Browser-এ API key দিলে visitor DevTools/network থেকে key দেখতে পারে। তাই production-এর জন্য:
- GitHub Pages = frontend
- আলাদা PHP/Node/Cloudflare Worker/Vercel backend = API proxy
- API key শুধু backend environment variable-এ রাখুন

এই ZIP-এ `bot_fixed.php`-ও দেওয়া আছে। সেটি PHP hosting/webhook server-এ ব্যবহার করতে পারবেন; `FF_API_KEYS` environment variable-এ comma-separated API keys দিতে হবে।

## কী কী ঠিক করা হয়েছে

- Original PHP-এর invalid `YOUR ADMIN CHAT ID` syntax ঠিক করা হয়েছে।
- Hard-coded API keys সরিয়ে environment variable করা হয়েছে।
- SSL verification বন্ধ করা ছিল—সেটি নিরাপদভাবে enable করা হয়েছে।
- Empty API-key পরিস্থিতি handle করা হয়েছে।
- Telegram HTML parse mode-এর সাথে `**bold**`/backtick formatting mismatch ঠিক করার জন্য message conversion/escaping যোগ করা হয়েছে।
- GitHub Pages-এর জন্য আলাদা responsive static dashboard তৈরি করা হয়েছে।
- Search, refresh, favorites, history, stats, raw JSON এবং settings যোগ করা হয়েছে।
- Browser localStorage-এ history/favorites রাখা হয়।
- API response-এর নতুন/অতিরিক্ত object sections-ও generic renderer দিয়ে দেখানো হয়।

## API/CORS

Browser থেকে API call করতে API server-এ CORS অনুমোদিত থাকতে হবে। CORS block হলে GitHub Pages frontend সরাসরি API call করতে পারবে না—তখন backend proxy লাগবে।

## Files

- `index.html` — GitHub Pages website
- `assets/style.css` — responsive UI
- `assets/app.js` — search/favorites/history/stats/API logic
- `bot_fixed.php` — corrected Telegram PHP bot backend
- `README.md` — setup/security guide
