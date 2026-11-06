(function () {
    var always = document.getElementById("wiz_sub_always");
    var dates = document.getElementById("wiz_sub_dates");
    always.addEventListener("change", function () { dates.style.display = always.checked ? "none" : ""; });

    var startNow = document.getElementById("wiz_vote_start_now");
    startNow.addEventListener("click", function () {
        var subEnd = document.getElementById("wiz_sub_end").value;
        var voteStart = document.getElementById("wiz_vote_start");
        if (subEnd) voteStart.value = subEnd;
        else alert("Set a submissions close date first, or enter the voting start time directly.");
    });

    var format = document.getElementById("wiz_format");
    var judgesFields = document.getElementById("wiz_judges_fields");
    format.addEventListener("change", function () {
        judgesFields.style.display = format.value === "public" ? "none" : "";
    });
})();
