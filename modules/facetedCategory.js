// Make the list of category links collapsible

function createToggleButton() {
  var catlinksToggle = document.createElement('button');
  catlinksToggle.classList.add('fw-catlinks-toggle');

  return catlinksToggle;
}

function main() {
  var directCats = mw.config.get('wgDirectCategories');

  var catlinks = document.querySelector('#mw-normal-catlinks');
  var catlinkItems = document.querySelectorAll('#mw-normal-catlinks li');

  if (!catlinks || directCats.length == catlinkItems.length) {
    return;
  }
  console.log(catlinkItems.length);
  for (var i = 0, len = catlinkItems.length; i < len; i++) {
    if (!directCats.includes(catlinkItems[i].innerText)) {
      catlinkItems[i].classList.add('collapsible');
    }
  }

  var catlinksToggle = createToggleButton();
  document.querySelector('#mw-normal-catlinks ul').append(catlinksToggle);
  catlinksToggle.addEventListener('click', function () {
    catlinks.classList.toggle('collapsed');
  });
}

main();
