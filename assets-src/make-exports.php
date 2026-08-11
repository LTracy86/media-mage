<?php
// Add the hat band and the media glyph to a wp.org export.
// Drawn at the export's own resolution, not upscaled from the 64 master, so the
// glyph is the one place with finer detail than the rest of the sprite. That is
// deliberate: the spec says the glyph is what rewards looking at 128 or larger.
$G="#1E2225"; $RIM="#3A3A3C"; $BAND="#14569B"; $GLYPH="#00AEEE";

function build($S, $src, $out) {
  global $G,$RIM,$BAND,$GLYPH;
  $f = $S / 64;
  $im = imagecreatefrompng($src);
  $g = [];
  for ($y=0;$y<$S;$y++) for ($x=0;$x<$S;$x++) {
    $c = imagecolorat($im, intdiv($x,$f), intdiv($y,$f));
    $g[$y][$x] = sprintf("#%02X%02X%02X", ($c>>16)&0xFF, ($c>>8)&0xFF, $c&0xFF);
  }
  $span = function($g,$y) use($G,$RIM,$S) { $l=-1;$r=-1;
    for($x=2;$x<$S-2;$x++){ $h=$g[$y][$x]; if($h===$G||$h===$RIM) continue; if($l<0)$l=$x; $r=$x; }
    return [$l,$r]; };

  // band across the base of the cone, following the silhouette
  $y0=(int)round(42*$f); $y1=(int)round(48*$f); $inset=(int)round(2*$f);
  for($y=$y0;$y<=$y1;$y++){ [$l,$r]=$span($g,$y); if($l<0)continue;
    for($x=$l+$inset;$x<=$r-$inset;$x++) $g[$y][$x]=$BAND; }
  foreach([$y0-1,$y1+1] as $sy){ [$l,$r]=$span($g,$sy); if($l<0)continue;
    for($x=$l+$inset;$x<=$r-$inset;$x++) $g[$sy][$x]=$G; }

  $put = function(&$g,$x,$y,$h) use($S){ if($x>=0&&$y>=0&&$x<$S&&$y<$S) $g[$y][$x]=$h; };
  // Everything below stays INSIDE the band. A cyan glyph that strays onto the
  // cyan body vanishes, which is what the first attempt did with the sun.
  $put = function(&$g,$x,$y,$h) use($S,$y0,$y1){
    if($x>=0&&$y>=$y0&&$x<$S&&$y<=$y1) $g[$y][$x]=$h;
  };
  $cx    = (int)round(34*$f);
  $baseY = $y1 - (int)round(1.0*$f);
  $H     = (int)round(3.5*$f);
  $W     = (int)round(6.5*$f);

  for($i=0;$i<$H;$i++){
    $half=(int)round($W - $i*$W/$H);
    for($x=$cx-$half;$x<=$cx+$half;$x++) $put($g,$x,$baseY-$i,$GLYPH);
  }
  $cx2=$cx-$W-(int)round(0.8*$f); $H2=(int)round($H*0.6); $W2=(int)round($W*0.6);
  for($i=0;$i<$H2;$i++){
    $half=(int)round($W2 - $i*$W2/$H2);
    for($x=$cx2-$half;$x<=$cx2+$half;$x++) $put($g,$x,$baseY-$i,$GLYPH);
  }
  $r    = 1.7*$f;
  $sunX = $cx - $W - (int)round(3.4*$f);
  $sunY = $baseY - $H + (int)round(0.6*$f);
  for($dy=-ceil($r);$dy<=ceil($r);$dy++) for($dx=-ceil($r);$dx<=ceil($r);$dx++)
    if($dx*$dx+$dy*$dy <= $r*$r) $put($g,(int)($sunX+$dx),(int)($sunY+$dy),$GLYPH);

  $o=imagecreatetruecolor($S,$S);
  $t=[];
  for($y=0;$y<$S;$y++) for($x=0;$x<$S;$x++){ $h=$g[$y][$x]; $t[$h]=1;
    imagesetpixel($o,$x,$y,imagecolorallocate($o,hexdec(substr($h,1,2)),hexdec(substr($h,3,2)),hexdec(substr($h,5,2)))); }
  imagepng($o,$out);
  printf("%-34s %dx%d  %d colours  %.1f KB\n", $out, $S, $S, count($t), filesize($out)/1024);
  return $o;
}
