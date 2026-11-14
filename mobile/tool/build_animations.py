#!/usr/bin/env python3
"""Builds assets/animations/*.webp from the app's own vector art.

The frames come from `test/core/design/animation_frames_render_test.dart`,
which paints `BuildingProgressArt` at each step of a loop to PNG with a
transparent background. This script assembles them into animated WebP, which
Flutter plays natively with full alpha (a GIF has 1-bit alpha and leaves jagged
edges on the warm gradient), and keeps each file under the size budget.

Run from mobile/:

    python3 tool/build_animations.py            # renders frames, writes assets
    python3 tool/build_animations.py --frames /tmp/dir   # reuse rendered frames

Requires Flutter on PATH and Pillow (PIL) with WebP support.
"""
from __future__ import annotations

import argparse
import glob
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile

from PIL import Image

MOBILE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT_DIR = os.path.join(MOBILE, 'assets', 'animations')
TEST = os.path.join('test', 'core', 'design', 'animation_frames_render_test.dart')
BUDGET_BYTES = 200 * 1024


def render_frames(out: str) -> None:
    cmd = ['flutter', 'test', TEST, f'--dart-define=N360_FRAMES_DIR={out}']
    print('>', ' '.join(cmd))
    subprocess.run(cmd, cwd=MOBILE, check=True)


def encode(frames: list[Image.Image], duration_ms: int, **kw) -> bytes:
    buf = io.BytesIO()
    frames[0].save(
        buf,
        format='WEBP',
        save_all=True,
        append_images=frames[1:],
        duration=duration_ms,
        loop=0,
        minimize_size=True,
        method=6,
        **kw,
    )
    return buf.getvalue()


def build(name: str, frame_dir: str) -> int:
    with open(os.path.join(frame_dir, f'{name}.json'), encoding='utf-8') as f:
        meta = json.load(f)
    paths = sorted(glob.glob(os.path.join(frame_dir, f'{name}_*.png')))
    if len(paths) != meta['frames']:
        sys.exit(f'{name}: expected {meta["frames"]} frames, found {len(paths)}')
    frames = [Image.open(p).convert('RGBA') for p in paths]

    # Flat colours and a mostly static picture compress best lossless; the
    # encoder only stores what changes between frames. Fall back to lossy with
    # a clean alpha channel if a loop ever outgrows the budget.
    attempts = [
        dict(lossless=True, quality=100),
        dict(lossless=False, quality=90, alpha_quality=100),
        dict(lossless=False, quality=80, alpha_quality=100),
    ]
    data = b''
    for kw in attempts:
        data = encode(frames, meta['frame_ms'], **kw)
        if len(data) <= BUDGET_BYTES:
            break
    if len(data) > BUDGET_BYTES:
        sys.exit(f'{name}: {len(data)} bytes is over the {BUDGET_BYTES} byte budget even lossy')

    os.makedirs(OUT_DIR, exist_ok=True)
    target = os.path.join(OUT_DIR, f'{name}.webp')
    with open(target, 'wb') as f:
        f.write(data)
    print(f'{name}.webp  {len(frames)} frames @ {meta["frame_ms"]}ms  {meta["width"]}x{meta["height"]}  {len(data) / 1024:.1f} KB')
    return len(data)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--frames', help='A directory of frames already rendered; skips the Flutter test.')
    args = parser.parse_args()

    tmp = None
    frame_dir = args.frames
    if frame_dir is None:
        tmp = tempfile.mkdtemp(prefix='n360_frames_')
        frame_dir = tmp
        render_frames(frame_dir)
    try:
        names = sorted({os.path.basename(p)[:-5] for p in glob.glob(os.path.join(frame_dir, '*.json'))})
        if not names:
            sys.exit(f'no frame sidecars in {frame_dir}')
        for name in names:
            build(name, frame_dir)
    finally:
        if tmp is not None:
            shutil.rmtree(tmp, ignore_errors=True)


if __name__ == '__main__':
    main()
