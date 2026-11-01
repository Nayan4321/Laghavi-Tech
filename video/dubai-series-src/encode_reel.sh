#!/bin/bash
# encode_reel.sh <frames dir> <presenter start s> <duration s> <opening end s> <name> <opening clip> <border png>
# Reel layout (EP5): full-frame map + graphics, presenter in a rounded window top-right (714,296 286x286).
set -e
cd "$(dirname "$0")"
FR=$1; SS=$2; DUR=$3; HK=$4; NAME=$5; HC=$6; BORDER=$7
V=${V:-"/c/Users/nayan/Downloads/H&S - Vikas dubai final 2 videos.mp4"}
FF=${FF:-mg/node_modules/ffmpeg-static/ffmpeg.exe}
OUT=${OUT:-../}; mkdir -p "$OUT"
CROP=${CROP:-640:642:205:555}
MASK="color=c=white:s=286x286:r=30:d=$DUR,format=gray,geq=lum='255*lte(hypot(max(0\,abs(X-143)-125)\,max(0\,abs(Y-143)-125))\,18)'"
# 1) editor file: graphics, presenter window in chroma green
[ -n "$SKIPG" ] || "$FF" -v error -y -f lavfi -i "color=c=0x00B140:s=1080x1920:r=30:d=$DUR" -framerate 30 -i $FR/%05d.png -i $HC -i $BORDER \
  -filter_complex "[0][2:v]overlay=eof_action=pass:enable='lt(t,$HK)'[a];[a][1]overlay=format=auto:shortest=1[b];\
color=c=0x00B140:s=286x286:r=30:d=$DUR[g];$MASK[m];[g][m]alphamerge[gw];[b][gw]overlay=714:296:enable='gte(t,$HK)'[c];\
[c][3]overlay=enable='gte(t,$HK)',format=yuv420p" -t $DUR -c:v libx264 -crf 16 -preset slow -movflags +faststart "$OUT/${NAME}_graphics_greenscreen.mp4"
# 2) review composite with the presenter + his audio
"$FF" -v error -y -ss $SS -t $DUR -i "$V" -framerate 30 -i $FR/%05d.png -i $HC -i $BORDER -filter_complex "\
[0:v]fps=30,scale=1080:1920,crop=$CROP,scale=286:286[wc];$MASK[m];[wc][m]alphamerge[wr];\
color=c=black:s=1080x1920:r=30:d=$DUR[bk];[bk][2:v]overlay=eof_action=pass:enable='lt(t,$HK)'[a];[a][1:v]overlay=format=auto:shortest=1[b];\
[b][wr]overlay=714:296:enable='gte(t,$HK)'[c];[c][3]overlay=enable='gte(t,$HK)',format=yuv420p[v]" \
  -map "[v]" -map 0:a -t $DUR -c:v libx264 -crf 18 -preset medium -c:a aac -b:a 160k -movflags +faststart "$OUT/${NAME}_preview_with_presenter.mp4"
echo done $NAME
