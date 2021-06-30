'use strict';

const Page = require('wdio-mediawiki/Page');

class CategoryIntersectionSearchPage extends Page {
  get pages() {
    return $('#mw-pages li');
  }
  open(subPage = false) {
    let title = 'Special:CategoryIntersectionSearch';
    if (subPage) {
      title += '/' + subPage;
    }
    super.openTitle(title);
  }
}

module.exports = new CategoryIntersectionSearchPage();
