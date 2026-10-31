#!/bin/bash
# encode.sh <frames dir> <presenter start s> <duration s> <hook end s> <name> [hook composite clip]
set -e
cd "$(dirname "$0")"
FR=$1; SS=$2; DUR=$3; HK=$4; NAME=$5; HC=${6:-}
V=/root/.claude/uploads/bcd858e8-38d4-52a6-9a1a-e0ed19fccb35/33cafb57-HS_-_Vikas_dubai_final_2_videos_1_1.mp4
OUT=/home/user/Laghavi-Tech/video; mkdir -p $OUT
if [ -n "$HC" ]; then HIN="-i $HC"; EN="lt(t,$HK)"; else EN=0;  HIN="-f lavfi -i color=c=black:s=1080x1920:r=30:d=0.1"; fi
# 1) editor file: graphics on chroma green (presenter window + opening hook are green; EP2 opening already has the Burj composite)
[ -n "$SKIPG" ] || ffmpeg -v error -y -f lavfi -i "color=c=0x00B140:s=1080x1920:r=30:d=$DUR" -framerate 30 -i $FR/%05d.png $HIN -filter_complex "\
[0][2:v]overlay=eof_action=pass:enable='$EN'[b];[b][1]overlay=format=auto:shortest=1,format=yuv420p" \
  -c:v libx264 -crf 16 -preset slow -movflags +faststart $OUT/${NAME}_graphics_greenscreen.mp4
# 2) review composite with the presenter + his audio
ffmpeg -v error -y -ss $SS -t $DUR -i $V -framerate 30 -i $FR/%05d.png -i winmask.png $HIN -filter_complex "\
[0:v]fps=30,scale=1080:1920,split[f][w];[w]crop=${CROP:-800:1013:140:230},scale=340:430[wc];[wc][2:v]alphamerge[wr];\
color=c=black:s=1080x1920:r=30:d=$DUR[bk];[bk][f]overlay=enable='lt(t,$HK)'[b1];[b1][3:v]overlay=eof_action=pass:enable='$EN'[b1b];\
[b1b][wr]overlay=56:1268:enable='gte(t,$HK)'[b2];[b2][1:v]overlay=format=auto:shortest=1,format=yuv420p[v]" \
  -map "[v]" -map 0:a -c:v libx264 -crf 18 -preset medium -c:a aac -b:a 160k -movflags +faststart $OUT/${NAME}_preview_with_presenter.mp4
echo done $NAME
