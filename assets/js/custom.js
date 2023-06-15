// Initialize tree categories
jQuery(document).ready(function () {
    jQuery("#tree").Tree();
});

// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
})

var texti = document.getElementById('inputImport');
var btni = document.getElementById('copyImport');
var textu = document.getElementById('inputUp');
var btnu = document.getElementById('copyUp');

btni.onclick = function () {
    texti.select();
    document.execCommand("copy");
}
btnu.onclick = function () {
    textu.select();
    document.execCommand("copy");
}

setTimeout(upAjaxProgressBar, 20000);

// Up progress bar
function upAjaxProgressBar() {
    jQuery(function ($) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'up_progress_bar'},
            dataType: 'json',
            success: function (response) {
                if (response) {
                    if ('step_item' in response) {
                        document.getElementById('upAjaxStep').style.width = response.step_item + '%';
                        $('#upAjaxStep').html(response.step_item + '%');
                    }

                    if ('total_item' in response) {
                        document.getElementById('upAjaxTotal').style.width = response.total_item + '%';
                        $('#upAjaxTotal').html(response.total_item + '%');
                    }

                    if ('progress_icon' in response) {
                        document.querySelector(".oasis-process-icon").innerHTML = response.progress_icon;
                    }

                    if ('progress_step_text' in response) {
                        document.querySelector('.oasis-process-text').innerHTML = response.progress_step_text;
                    }

                    if ('status_progress' in response) {
                        if (response.status_progress == true) {
                            addAnimatedBar('progress-bar-striped progress-bar-animated');
                            setTimeout(upAjaxProgressBar, 5000);
                        } else {
                            removeAnimatedBar('progress-bar-striped progress-bar-animated');
                            setTimeout(upAjaxProgressBar, 60000);
                        }
                    }
                } else {
                    removeAnimatedBar('progress-bar-striped progress-bar-animated');
                    setTimeout(upAjaxProgressBar, 600000);
                }
            }
        });
    });
}

function addAnimatedBar(classStr) {
    let lassArr = classStr.split(' ');

    lassArr.forEach(function(item, index, array) {
        let upAjaxTotal = document.getElementById('upAjaxTotal');

        if (upAjaxTotal && !upAjaxTotal.classList.contains(item)) {
            upAjaxTotal.classList.add(item);
        }

        let upAjaxStep = document.getElementById('upAjaxStep');

        if (upAjaxStep && !upAjaxStep.classList.contains(item)) {
            upAjaxStep.classList.add(item);
        }
    });
}

function removeAnimatedBar(classStr) {
    let lassArr = classStr.split(' ');

    lassArr.forEach(function(item, index, array) {
        let upAjaxTotal = document.getElementById('upAjaxTotal');

        if (upAjaxTotal && upAjaxTotal.classList.contains(item)) {
            upAjaxTotal.classList.remove(item);
        }

        let upAjaxStep = document.getElementById('upAjaxStep');

        if (upAjaxStep && upAjaxStep.classList.contains(item)) {
            upAjaxStep.classList.remove(item);
        }
    });
}
