// Listening Party — the track list is server-rendered (see
// class-listening.php), so this file only needs to handle one small
// thing: pausing every other <audio> element the moment one starts
// playing, so listening through entries live doesn't accidentally
// stack multiple tracks on top of each other.
document.addEventListener('DOMContentLoaded', function () {
    var players = document.querySelectorAll('.bh-listening-audio');
    players.forEach(function (audio) {
        audio.addEventListener('play', function () {
            players.forEach(function (other) {
                if (other !== audio) other.pause();
            });
        });
    });
});
