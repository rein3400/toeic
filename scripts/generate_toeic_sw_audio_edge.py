#!/usr/bin/env python
"""Generate literal TOEIC SW prompt audio with Microsoft Edge neural TTS.

This is a provider fallback for MiniMax usage-limit failures. It reads the
manifest audio_script text verbatim, writes a loud WAV file plus SRT subtitle,
and refuses common AI-preface phrases.
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
import tempfile
import wave
from pathlib import Path

import miniaudio


FORBIDDEN = [
    re.compile(r"\bsure\b", re.I),
    re.compile(r"\bcertainly\b", re.I),
    re.compile(r"\bokay\b", re.I),
    re.compile(r"\bok\b", re.I),
    re.compile(r"as an ai", re.I),
    re.compile(r"\bi will\b", re.I),
    re.compile(r"\bi'll\b", re.I),
    re.compile(r"\bhere is\b", re.I),
    re.compile(r"let me", re.I),
    re.compile(r"\bi can\b", re.I),
    re.compile(r"read a loud", re.I),
    re.compile(r"read aloud", re.I),
]


def assert_clean(text: str, label: str) -> None:
    for pattern in FORBIDDEN:
        if pattern.search(text):
            raise RuntimeError(f"{label} contains forbidden audio text: {pattern.pattern}")


def is_wav(path: Path) -> bool:
    if not path.exists() or path.stat().st_size < 12_000:
        return False
    with path.open("rb") as handle:
        header = handle.read(12)
    return header[:4] == b"RIFF" and header[8:12] == b"WAVE"


def decode_to_wav(media_path: Path, wav_path: Path, sample_rate: int) -> None:
    decoded = miniaudio.decode_file(
        str(media_path),
        output_format=miniaudio.SampleFormat.SIGNED16,
        nchannels=1,
        sample_rate=sample_rate,
    )
    with wave.open(str(wav_path), "wb") as wav:
        wav.setnchannels(decoded.nchannels)
        wav.setsampwidth(2)
        wav.setframerate(decoded.sample_rate)
        wav.writeframes(decoded.samples)


def synthesize_edge(text: str, wav_path: Path, srt_path: Path, voice: str, rate: str, volume: str, sample_rate: int) -> None:
    wav_path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="toeic_sw_edge_") as temp_dir:
        temp = Path(temp_dir)
        text_path = temp / "prompt.txt"
        media_path = temp / "prompt.mp3"
        text_path.write_text(text, encoding="utf-8")
        cmd = [
            sys.executable,
            "-m",
            "edge_tts",
            "-f",
            str(text_path),
            "-v",
            voice,
            "--rate",
            rate,
            "--volume",
            volume,
            "--write-media",
            str(media_path),
            "--write-subtitles",
            str(srt_path),
        ]
        subprocess.run(cmd, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        decode_to_wav(media_path, wav_path, sample_rate)

    if not is_wav(wav_path):
        raise RuntimeError(f"Generated file is not a valid WAV: {wav_path}")
    if not srt_path.exists() or srt_path.stat().st_size < 20:
        raise RuntimeError(f"Missing subtitle sidecar: {srt_path}")
    assert_clean(srt_path.read_text(encoding="utf-8", errors="ignore"), str(srt_path))


def iter_audio_tasks(package_root: Path):
    for manifest_path in sorted(package_root.glob("package_*/manifest.json")):
        package_dir = manifest_path.parent
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        for task in manifest.get("speaking", []):
            audio_path = str(task.get("audio_path") or "").strip()
            if not audio_path:
                continue
            yield package_dir, task


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--package-root", default=str(Path(__file__).resolve().parents[1] / "content" / "generated" / "toeic_sw"))
    parser.add_argument("--voice", default="en-US-GuyNeural")
    parser.add_argument("--uk-voice", default="en-GB-RyanNeural")
    parser.add_argument("--alternate-us-uk", action="store_true")
    parser.add_argument("--rate", default="+0%")
    parser.add_argument("--volume", default="+40%")
    parser.add_argument("--sample-rate", type=int, default=32000)
    parser.add_argument("--overwrite", action="store_true")
    parser.add_argument("--limit", type=int, default=0)
    args = parser.parse_args()

    package_root = Path(args.package_root)
    generated = 0
    skipped = 0
    checked = 0
    failures: list[str] = []

    for package_dir, task in iter_audio_tasks(package_root):
        checked += 1
        question = int(task.get("question_number") or 0)
        label = f"{package_dir.name} Speaking Q{question}"
        script = str(task.get("audio_script") or "").strip()
        transcript = str(task.get("audio_transcript") or "").strip()
        audio_rel = str(task.get("audio_path") or "")
        wav_path = package_dir / audio_rel.replace("/", "\\")
        srt_path = wav_path.with_suffix(".srt")

        try:
            if not script:
                raise RuntimeError(f"{label} has no audio_script")
            if script != transcript:
                raise RuntimeError(f"{label} audio_script and audio_transcript differ")
            assert_clean(script, label)

            if not args.overwrite and is_wav(wav_path) and srt_path.exists():
                skipped += 1
                continue

            package_number = int(str(package_dir.name).split("_")[-1])
            voice = args.uk_voice if args.alternate_us_uk and package_number % 2 == 0 else args.voice
            synthesize_edge(script, wav_path, srt_path, voice, args.rate, args.volume, args.sample_rate)
            print(str(wav_path))
            print(str(srt_path))
            generated += 1
        except Exception as exc:
            failures.append(f"{label} failed: {exc}")

        if args.limit and generated + skipped >= args.limit:
            break

    if checked != 70 and not args.limit:
        failures.append(f"Expected 70 TOEIC SW prompt audio tasks, found {checked}.")
    if failures:
        print("TOEIC SW Edge audio generation failed:", file=sys.stderr)
        for failure in failures:
            print(f"- {failure}", file=sys.stderr)
        return 1

    print("TOEIC SW Edge audio generation complete.")
    print(f"Checked: {checked}")
    print(f"Generated: {generated}")
    print(f"Skipped: {skipped}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
