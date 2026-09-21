package com.sbkautotrading.chat

import android.app.ActivityManager
import android.app.ApplicationExitInfo
import android.content.Context
import android.os.Build
import java.io.File
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL

/**
 * The crash reporter the first calls APKs did not have.
 *
 * September 2026: the app closed the moment it opened on the owner's phone,
 * twice, and the JavaScript reporter (src/guard.js) sent nothing - a crash
 * below JavaScript, or one that kills the process before a network request
 * can leave, never reaches it. This one lives in Android itself and is
 * installed in MainApplication.attachBaseContext, before anything else in the
 * app runs, so it sees:
 *
 *   - any Java/Kotlin exception that would close the app, including a fatal
 *     JavaScript error (React Native re-throws those as a Java exception);
 *     the stack is sent to api/crash.php BEFORE the process dies (a sender
 *     thread, waited for up to 4 s), and also written to a file in case
 *     there was no signal - that file goes on the next start;
 *   - on Android 11+, WHY the previous run ended, from the system's own
 *     record (ActivityManager.getHistoricalProcessExitReasons) - including a
 *     native crash's tombstone on Android 12+, which no Java handler can see;
 *   - the first start of each version, with the phone's model, Android
 *     version, ABIs and memory page size, so "is the new APK really the one
 *     on the phone?" is answered by the phone itself.
 *
 * Nothing personal is sent: no account, no messages, no location.
 */
object SbkCrash {
  private const val ENDPOINT = "https://chat-application.sbkautotrading.com/api/crash.php"
  private const val PENDING = "sbk-last-crash.txt"
  private const val PREFS = "sbk-crash"

  @Volatile private var installed = false
  private lateinit var app: Context

  @JvmStatic
  fun install(context: Context) {
    if (installed) return
    installed = true
    app = context

    val previous = Thread.getDefaultUncaughtExceptionHandler()
    Thread.setDefaultUncaughtExceptionHandler { thread, error ->
      try {
        val body = header() + "\nCRASH on thread '" + thread.name + "'\n" + chain(error)
        writePending(body)
        val sender = Thread { if (post(body)) clearPending() }
        sender.start()
        sender.join(4000)
      } catch (ignored: Throwable) {
      }
      if (previous != null) {
        previous.uncaughtException(thread, error)
      } else {
        android.os.Process.killProcess(android.os.Process.myPid())
        System.exit(10)
      }
    }

    try {
      reportEarlierTrouble()
    } catch (ignored: Throwable) {
    }
  }

  /** Something failed and was handled - worth knowing, not worth a crash. */
  @JvmStatic
  fun report(what: String, error: Throwable) {
    try {
      val body = header() + "\n" + what + "\n" + chain(error)
      Thread { post(body) }.start()
    } catch (ignored: Throwable) {
    }
  }

  private fun reportEarlierTrouble() {
    val prefs = app.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    val parts = StringBuilder()

    val pending = readPending()
    if (pending != null) {
      parts.append("(a crash from an earlier run that could not be sent then)\n").append(pending).append("\n")
    }

    var exitTs = prefs.getLong("exitTs", 0L)
    if (Build.VERSION.SDK_INT >= 30) {
      val found = ExitReasons.collect(app, exitTs)
      if (found != null) {
        parts.append(found.first)
        exitTs = found.second
      }
    }

    val version = versionTag()
    val firstStart = prefs.getString("started", "") != version
    if (parts.isEmpty() && !firstStart) return

    val body = header() + "\n" + (if (firstStart) "first start of this version\n" else "") + parts.toString()
    val savedExitTs = exitTs
    val sender = Thread {
      if (post(body)) {
        clearPending()
        prefs.edit().putString("started", version).putLong("exitTs", savedExitTs).apply()
      }
    }
    sender.start()
    // Waited for only when there is something to say: a report still in
    // flight when the same crash happens again would be lost with it.
    sender.join(4000)
  }

  private fun header(): String {
    var page = 0L
    try {
      page = android.system.Os.sysconf(android.system.OsConstants._SC_PAGESIZE)
    } catch (ignored: Throwable) {
    }
    return "[android] app " + versionTag() +
      " | Android " + Build.VERSION.RELEASE + " (SDK " + Build.VERSION.SDK_INT + ")" +
      " | " + Build.MANUFACTURER + " " + Build.MODEL +
      " | abi " + Build.SUPPORTED_ABIS.joinToString(",") +
      " | page " + page
  }

  private fun versionTag(): String {
    return try {
      val pi = app.packageManager.getPackageInfo(app.packageName, 0)
      val code = if (Build.VERSION.SDK_INT >= 28) pi.longVersionCode else pi.versionCode.toLong()
      pi.versionName + " (" + code + ")"
    } catch (ignored: Throwable) {
      "?"
    }
  }

  /** The exception and every "Caused by", 25 frames each - the root cause is usually the last one. */
  private fun chain(error: Throwable): String {
    val b = StringBuilder()
    var e: Throwable? = error
    var depth = 0
    val seen = HashSet<Throwable>()
    while (e != null && depth < 8 && seen.add(e)) {
      if (depth > 0) b.append("Caused by: ")
      b.append(e.javaClass.name).append(": ").append(e.message ?: "").append('\n')
      val frames = e.stackTrace
      for (i in frames.indices) {
        if (i >= 25) {
          b.append("    ... ").append(frames.size - 25).append(" more\n")
          break
        }
        b.append("    at ").append(frames[i].toString()).append('\n')
      }
      e = e.cause
      depth++
    }
    return b.toString()
  }

  private fun post(body: String): Boolean {
    var c: HttpURLConnection? = null
    return try {
      c = URL(ENDPOINT).openConnection() as HttpURLConnection
      c.requestMethod = "POST"
      c.doOutput = true
      c.connectTimeout = 3500
      c.readTimeout = 3500
      c.setRequestProperty("Content-Type", "text/plain; charset=utf-8")
      c.setRequestProperty("User-Agent", "SBKApp-android")
      val text = if (body.length > 30000) body.substring(0, 30000) else body
      c.outputStream.use { it.write(text.toByteArray(Charsets.UTF_8)) }
      c.responseCode in 200..299
    } catch (ignored: Throwable) {
      false
    } finally {
      c?.disconnect()
    }
  }

  private fun pendingFile(): File = File(app.filesDir, PENDING)

  private fun writePending(body: String) {
    try {
      pendingFile().writeText(body)
    } catch (ignored: Throwable) {
    }
  }

  private fun readPending(): String? {
    return try {
      val f = pendingFile()
      if (f.exists()) f.readText() else null
    } catch (ignored: Throwable) {
      null
    }
  }

  private fun clearPending() {
    try {
      pendingFile().delete()
    } catch (ignored: Throwable) {
    }
  }

  /** Android 11+ only (every call is behind an SDK_INT >= 30 check): the system's own record of how earlier runs ended. */
  private object ExitReasons {
    fun collect(context: Context, since: Long): Pair<String, Long>? {
      val am = context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
      val list = am.getHistoricalProcessExitReasons(null, 0, 5)
      val b = StringBuilder()
      var newest = since
      for (x in list) {
        if (x.timestamp <= since) continue
        if (x.timestamp > newest) newest = x.timestamp
        val r = x.reason
        val bad = r == ApplicationExitInfo.REASON_CRASH ||
          r == ApplicationExitInfo.REASON_CRASH_NATIVE ||
          r == ApplicationExitInfo.REASON_ANR ||
          r == ApplicationExitInfo.REASON_INITIALIZATION_FAILURE
        if (!bad) continue
        b.append("earlier run ended: ").append(reasonName(r))
          .append(" | status ").append(x.status)
          .append(" | ").append(java.util.Date(x.timestamp).toString())
          .append(" | ").append(x.description ?: "")
          .append('\n')
        if (r == ApplicationExitInfo.REASON_CRASH_NATIVE || r == ApplicationExitInfo.REASON_ANR) {
          try {
            val stream = x.traceInputStream
            if (stream != null) {
              stream.use { b.append(printable(it, 16000)).append('\n') }
            }
          } catch (ignored: Throwable) {
          }
        }
      }
      return if (b.isEmpty()) null else Pair(b.toString(), newest)
    }

    private fun reasonName(r: Int): String = when (r) {
      ApplicationExitInfo.REASON_CRASH -> "CRASH (java)"
      ApplicationExitInfo.REASON_CRASH_NATIVE -> "CRASH_NATIVE"
      ApplicationExitInfo.REASON_ANR -> "ANR"
      ApplicationExitInfo.REASON_INITIALIZATION_FAILURE -> "INITIALIZATION_FAILURE"
      else -> "reason " + r
    }

    /** A tombstone is binary; its readable parts (signal, abort message, library and function names) are what matter. */
    private fun printable(input: InputStream, max: Int): String {
      val raw = ByteArray(1024 * 1024)
      var n = 0
      while (n < raw.size) {
        val got = input.read(raw, n, raw.size - n)
        if (got <= 0) break
        n += got
      }
      val out = StringBuilder()
      val run = StringBuilder()
      for (i in 0 until n) {
        val ch = raw[i].toInt() and 0xff
        if (ch in 32..126) {
          run.append(ch.toChar())
        } else {
          if (run.length >= 4) {
            out.append(run).append('\n')
            if (out.length >= max) break
          }
          run.setLength(0)
        }
      }
      if (run.length >= 4 && out.length < max) out.append(run)
      return if (out.length > max) out.substring(0, max) else out.toString()
    }
  }
}
