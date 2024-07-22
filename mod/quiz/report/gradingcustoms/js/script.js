$(document).ready(async function() {
    try {
        const usageid = getQueryParam('usageid');
        if(usageid){
            loadInput();

            // Add the event listener for the form submit event
            const form = document.getElementById('manualgradingform');
            form.addEventListener('submit', function(event) {
                saveInput();
            });
        } else {
            localStorage.clear();
            document.getElementById('statusFilter').addEventListener('change', function() {
                var selectedStatus = this.value;
                var currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('status_filter', selectedStatus);
                window.location.href = currentUrl.href;
            });

            document.getElementById('searchButton').addEventListener('click', search);
            document.getElementById('searchFilter').addEventListener('keypress', function(event) {
                if ((event.key === 'Enter' || event.keyCode === 13) && this.value.trim() !== '') {
                    search();
                }
            });
            
            const searchFilter = getQueryParam('search_filter');
            if(searchFilter){
                $('#searchFilter').val(searchFilter);
            }

            document.getElementById('clear-filter').addEventListener('click',  function(event) {
                var currentUrl = new URL(window.location.href);
                if (currentUrl.searchParams.has('search_filter')) {
                    currentUrl.searchParams.delete('search_filter');
                    window.location.href = currentUrl.href;
                }
            });
        }
    } catch (error) {
        console.error('Error in fetching data: ', error);
    }
});

function search() {
    var searchValue = document.getElementById('searchFilter').value.trim(); // Get the search input value
    if (searchValue !== '') { // Check if the search value is not empty
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('search_filter', searchValue);
        window.location.href = currentUrl.href; // Redirect to the updated URL with search filter
    }
}

function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

function formatGrade(value) {
    if(value){
        return parseFloat(value).toString();
    }
    return ''
}

function saveInput() {
    var inputs = document.querySelectorAll('input[name*="_-mark"]');
    inputs.forEach(function(input) {
        localStorage.setItem(input.id, formatGrade(input.value));
    });
}

function loadInput() {
    var inputs = document.querySelectorAll('input[name*="_-mark"]');
    inputs.forEach(function(input) {
        const savedInput = localStorage.getItem(input.id);
        if (savedInput !== null) {
            document.getElementById(input.id).value = savedInput;
            localStorage.removeItem(input.id);
        }
    });
}
