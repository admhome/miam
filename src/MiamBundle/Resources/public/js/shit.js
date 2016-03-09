app.shit = {
	init: function() {
		this.sidebar.init();
		this.items.init();
	},

	sidebar: {
		init: function() {
			// Indentation des catégories
			$(".sidebar .row").each(function() {
				$(this).children(".name").css('padding-left', $(this).parents(".rowChildren").length+"em");
			});

			// Sélection d'un flux ou d'une catégorie
			$(".sidebar .row").click(function(e) {
				app.shit.items.type = $(this).data('type');

				switch(app.shit.items.type) {
					case 'feed':
						app.shit.items.feed = $(this).data('feed'); break;
					case 'category':
					    app.shit.items.category = $(this).data('category'); break;
				}

				app.shit.items.refresh();

				$(".sidebar .row").removeClass("selected");
				$(this).addClass("selected");
			});

			// Subcategories toggle
			$(".sidebar .row .toggle").click(function(e) {
				var row = $(this).closest(".row");
				var rowChildren = $(".sidebar .rowChildren[data-parent="+$(this).closest(".row").data("category")+"]");

				if(row.hasClass("expanded")) {
					row.removeClass("expanded");
					rowChildren.removeClass("expanded");
				} else {
					row.addClass("expanded");
					rowChildren.addClass("expanded");
				}

				e.stopPropagation();
			});

			// Sidebar toggle
			$(".sidebar_toggle").click(function() {
				$(".body_shit").toggleClass("hide_sidebar");
			});

			// Menu contextuel d'un flux ou d'une catégorie
			if(app.user && app.shit.items.subscriber && app.user.id == app.shit.items.subscriber) {
				$(".sidebar .row").contextmenu(function(e) {
					e.preventDefault();

					$(".sidebarRowMenu").remove();

					var type = $(this).data('type');
					
					var menu = $("<div>")
						.addClass("sidebarRowMenu")
						.css({
							left: e.clientX, 
							top: e.clientY
						})
						.attr('data-type', type)
					;

					if(type == 'feed') {
						menu.attr('data-feed', $(this).data('feed'));
					} else if(type == 'category') {
						menu.attr('data-category', $(this).data('category'));
					}

					var menuOption = $("<div>").addClass("option").text("Mark as read").attr('data-action', 'read');
					menu.append(menuOption);

					$(".body_shit").append(menu);

					$(".sidebarRowMenu .option").click(function(e) {
						var action = $(this).data("action");
						var type = $(this).closest(".sidebarRowMenu").data("type");

						if(action == 'read') {
							if(type == "feed") {
								app.shit.items.readFeed($(this).closest(".sidebarRowMenu").data("feed"));
							} else if(type == "category") {
								app.shit.items.readCategory($(this).closest(".sidebarRowMenu").data("category"));
							} else if(type == "all") {
								app.shit.items.readAll();
							}
						}
					});
				});
				
				$(document).click(function() {
					$(".sidebarRowMenu").remove();
				});

				setInterval(function() {
					app.shit.sidebar.refreshUnreadCounts();
				}, 300000);
			}

			this.countUnread();
			this.toggleUnreadCounts();
		},

		countUnread: function() {
			// Calculate unread counts for categories
			$(".sidebar .row[data-type='category']").each(function() {
				var category = $(this).data("category");
				var unreadCount = 0;

				$(".sidebar .rowChildren[data-parent="+category+"] .row[data-type='feed'] .unreadCount").each(function() {
					var count = parseInt($(this).text());
					if(!isNaN(count)) {
						unreadCount += count;
					}
				});

				$(".sidebar .row[data-category="+category+"] .unreadCount").text(unreadCount);
			});
		},

		toggleUnreadCounts: function() {
			// Show or hide unread counts
			$(".sidebar .row .unreadCount").each(function() {
				var unreadCount = parseInt($(this).text());
				if(!isNaN(unreadCount) && unreadCount > 0) {
					$(this).show();
				} else {
					$(this).hide();
				}
			});
		},

		refreshUnreadCounts: function() {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_get_unread_counts'),
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					$(".sidebar .row .unreadCount").each(function() {
						$(this).text(0);

						var feedId = $(this).closest(".row").data("feed");
						if(feedId && result.unreadCounts) {
							for(var i=0; i<result.unreadCounts.length; i++) {
								if(result.unreadCounts[i].feedId == feedId) {
									$(this).text(result.unreadCounts[i].count);
									break;
								}
							}
						}
					})

					app.shit.sidebar.countUnread();
					app.shit.sidebar.toggleUnreadCounts();
				}
			});
		},

		decrementUnreadCountForFeed: function(feed) {
			$(".sidebar .row[data-feed="+feed+"] .unreadCount").each(function() {
				var count = parseInt($(this).text());
				if(!isNaN(count)) {
					$(this).text(count - 1);
				}
			});

			this.countUnread();
			this.toggleUnreadCounts();
		}
	},

	items: {
		category: null,
		feed: null,
		subscriber: null,
		type: null,

		init: function() {
			app.items.onExpand = function(item) {
				if(!item.hasClass("read")) {
					app.shit.items.readItem(item);
				}
			}

			$(".item .star").on("click", function(e) {
				e.preventDefault();

				var item = $(this).closest(".item");
				
				if(item.hasClass("starred")) {
					app.shit.items.unstarItem(item);
				} else {
					app.shit.items.starItem(item);
				}

				e.stopPropagation();
			});
		},

		get: function(data, callback) {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_get_items'),
				data: data,
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					callback(result.items);
				}
			});
		},

		refresh: function() {
			this.get({
				category: app.shit.items.category,
				feed: app.shit.items.feed,
				subscriber: app.shit.items.subscriber,
				type: app.shit.items.type
			}, function(items) {
				$(".items").html(items);
				app.items.init();
				app.shit.items.init();
			});
		},

		readItem: function(item) {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_read_item', {id: item.data("item")}),
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					item.addClass("read");
					app.shit.sidebar.decrementUnreadCountForFeed(item.data('feed'));
				}
			});
		},

		readFeed: function(feedId) {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_read_feed_items', {id: feedId}),
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					app.shit.sidebar.refreshUnreadCounts();
				}
			});
		},

		readCategory: function(categoryId) {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_read_category_items', {id: categoryId}),
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					app.shit.sidebar.refreshUnreadCounts();
				}
			});
		},

		readAll: function() {
			var userId = app.shit.items.subscriber;
			if(userId) {
				$.ajax({
					type: "POST",
					url: Routing.generate('ajax_shit_read_user_items', {id: userId}),
					dataType: "json"
				}).done(function(result) {
					if(result.success) {
						app.shit.sidebar.refreshUnreadCounts();
					}
				});
			}
		},

		starItem: function(item) {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_star_item', {'id': item.data("item")}),
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					item.addClass("starred");
				}
			});
		},

		unstarItem: function(item) {
			$.ajax({
				type: "POST",
				url: Routing.generate('ajax_shit_unstar_item', {'id': item.data("item")}),
				dataType: "json"
			}).done(function(result) {
				if(result.success) {
					item.removeClass("starred");
				}
			});
		}
	}
}