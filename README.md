# SBK Auto Trading — poora project, aik jagah

Is folder mein SBK ka **saara code** hai. Har folder aik cheez ka hai:

| Folder | Kya hai | Kahan chalta hai |
|---|---|---|
| `portal/` | **Auction portal** — gaadiyan, bidding, statistics, admin panel | auction.sbkautotrading.com |
| `chat/` | **Live chat** — customer aur desk ki baat, calls, voice note, pictures | chat.sbkautotrading.com |
| `application/` | **Android app ka code** (React Native) — isi se APK banti hai | phone par |
| `app-download-page/` | App **download karne wala page** | chat-application.sbkautotrading.com |
| `wordpress/` | Website ke **2 file** — chat ka button/handover, aur app ka sign-in | sbkautotrading.com (mu-plugins) |
| `firebase-keys/` | **Push notification ki chaabiyan** — git mein kabhi nahi jatin | — |
| `SBK-Chat.apk` | Jo APK abhi live hai (1.0.9) | — |

## Samajhne wali 3 baatein

**1. Application ka apna code hai** — `application/` mein. Lekin **server** wahi hai jo chat ka hai:
app ki domain par sirf chhoti si file hain jo `chat/` wali asli file ko bula leti hain. Is liye
feature dono jagah aik jaise rehte hain, aur code sirf **aik jagah** theek karna parta hai.

**2. Database do hain, teen nahi:**
- **Auction database** — portal (gaadiyan, bids, statistics) **aur** chat ki saari tables. Portal,
  chat aur app teenon isi ko use karte hain.
- **WordPress database** — website ke apne customers. Chat is mein se sirf **parhta** hai.

**3. Push notification** ka code do jagah hai: bhejne wala hissa `chat/` ke server mein, aur lene
wala hissa `application/src/push.js` mein. Browser ko notification nahi aati kyunki browser phone
nahi hai — server wahi aik hai.

## Git

| Folder | GitHub |
|---|---|
| `portal/` | `MohsinRaza2000/sbk-auction` (private) — **live hai** |
| `chat/` | repo ka intezar — local git taiyar hai |
| `application/` | repo ka intezar — local git taiyar hai |
| `wordpress/` | repo ka intezar — local git taiyar hai |

Password, `.env` aur Firebase ki secret key **kabhi git par nahi jatin**.
